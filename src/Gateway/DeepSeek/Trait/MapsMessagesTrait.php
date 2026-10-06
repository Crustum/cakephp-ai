<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\DeepSeek\Trait;

use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Utility\Value;

/**
 * Maps messages to DeepSeek Chat Completions format.
 */
trait MapsMessagesTrait
{
    /**
     * Map the given messages to Chat Completions messages format.
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
     * Map a user message to Chat Completions format.
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
            'content' => [
                ['type' => 'text', 'text' => $message->content],
                ...$this->mapAttachments($message->attachments),
            ],
        ];
    }

    /**
     * Map an assistant message to Chat Completions format.
     *
     * @param \Crustum\Ai\Messages\AssistantMessage|\Crustum\Ai\Messages\Message $message Assistant message
     * @param array<int, array<string, mixed>> $chatMessages Chat messages
     * @return void
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): void
    {
        $msg = ['role' => 'assistant'];

        if (Value::filled($message->content)) {
            $msg['content'] = $message->content;
        }

        if (!$message instanceof AssistantMessage) {
            $chatMessages[] = $msg;

            return;
        }

        if (!$message->toolCalls->isEmpty()) {
            $msg['tool_calls'] = $message->toolCalls->map(
                fn(ToolCall $toolCall): array => $this->serializeToolCallToChat($toolCall),
            )->toList();

            /** @var array<string, mixed>|null $reasoningBlock */
            $reasoningBlock = collection($message->replayBlocks)->firstMatch(['type' => 'reasoning']);
            $msg['reasoning_content'] = $reasoningBlock['reasoning_content'] ?? '';
        }

        $chatMessages[] = $msg;
    }

    /**
     * Map a tool result message to Chat Completions format.
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
                'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
                'content' => $toolResult->text(),
            ];
        }
    }

    /**
     * Serialize a tool call DTO to Chat Completions array format.
     *
     * @param \Crustum\Ai\Responses\Data\ToolCall $toolCall Tool call
     * @return array<string, mixed>
     */
    protected function serializeToolCallToChat(ToolCall $toolCall): array
    {
        return [
            'id' => $toolCall->resultId ?? $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments ?: (object)[]),
            ],
        ];
    }
}
