<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Ollama\Trait;

use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Utility\Value;

/**
 * Maps messages to Ollama Chat API format.
 */
trait MapsMessagesTrait
{
    /**
     * Map the given messages to Ollama Chat API messages format.
     *
     * @param array<int, mixed> $messages Conversation messages
     * @param string|null $instructions System instructions
     * @return array<int, array<string, mixed>>
     */
    protected function mapMessagesToChat(array $messages, ?string $instructions = null): array
    {
        $chatMessages = [];

        if (Value::filled($instructions)) {
            $chatMessages[] = [
                'role' => 'system',
                'content' => $instructions,
            ];
        }

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $chatMessages),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $chatMessages),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $chatMessages),
            };
        }

        return $chatMessages;
    }

    /**
     * Map a user message to Ollama Chat API format.
     *
     * @param \Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Messages\Message $message User message
     * @param array<int, array<string, mixed>> $chatMessages Chat messages
     * @return void
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$chatMessages): void
    {
        if (!$message instanceof UserMessage || $message->attachments->isEmpty()) {
            $chatMessages[] = [
                'role' => 'user',
                'content' => $message->content,
            ];

            return;
        }

        $chatMessages[] = [
            'role' => 'user',
            'content' => $message->content,
            'images' => $this->mapAttachments($message->attachments),
        ];
    }

    /**
     * Map an assistant message to Ollama Chat API format.
     *
     * @param \Crustum\Ai\Messages\AssistantMessage|\Crustum\Ai\Messages\Message $message Assistant message
     * @param array<int, array<string, mixed>> $chatMessages Chat messages
     * @return void
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): void
    {
        $msg = [
            'role' => 'assistant',
            'content' => $message->content ?? '',
        ];

        if ($message instanceof AssistantMessage && !$message->toolCalls->isEmpty()) {
            $msg['tool_calls'] = $message->toolCalls->map(
                fn(ToolCall $toolCall): array => $this->serializeToolCallToChat($toolCall),
            )->toList();
        }

        $chatMessages[] = $msg;
    }

    /**
     * Map a tool result message to Ollama Chat API format.
     *
     * @param \Crustum\Ai\Messages\ToolResultMessage|\Crustum\Ai\Messages\Message $message Tool result message
     * @param array<int, array<string, mixed>> $chatMessages Chat messages
     * @return void
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$chatMessages): void
    {
        if (!$message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $chatMessages[] = [
                'role' => 'tool',
                'tool_name' => $toolResult->name,
                'content' => $this->serializeToolResultOutput($toolResult->result),
            ];
        }
    }

    /**
     * Serialize a tool call DTO to Ollama Chat API array format.
     *
     * Ollama's /api/chat message history shape only uses the `function` object for assistant
     * tool calls — no top-level `type` key. That key belongs on the /api/chat `tools`
     * definitions, not the message history.
     *
     * @param \Crustum\Ai\Responses\Data\ToolCall $toolCall Tool call
     * @return array<string, mixed>
     */
    protected function serializeToolCallToChat(ToolCall $toolCall): array
    {
        return [
            'function' => [
                'name' => $toolCall->name,
                'arguments' => $toolCall->arguments ?: (object)[],
            ],
        ];
    }

    /**
     * Serialize a tool result output value to a string.
     *
     * @param mixed $output Tool result output
     * @return string
     */
    protected function serializeToolResultOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        return is_array($output) ? (string)json_encode($output) : (string)$output;
    }
}
