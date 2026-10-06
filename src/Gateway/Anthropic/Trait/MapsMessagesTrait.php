<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Anthropic\Trait;

use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Utility\Value;

/**
 * Maps messages to Anthropic Messages API format.
 */
trait MapsMessagesTrait
{
    /**
     * Map the given messages to Anthropic Messages API format.
     *
     * @param array<int, mixed> $messages Conversation messages
     * @return array<int, array<string, mixed>>
     */
    protected function mapMessages(array $messages): array
    {
        $mapped = [];

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $mapped),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $mapped),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $mapped),
            };
        }

        return $mapped;
    }

    /**
     * Map a user message to Anthropic format.
     *
     * @param \Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Messages\Message $message User message
     * @param array<int, array<string, mixed>> $mapped Mapped messages
     * @return void
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$mapped): void
    {
        $content = [
            ['type' => 'text', 'text' => $message->content],
        ];

        if ($message instanceof UserMessage && !$message->attachments->isEmpty()) {
            $content = array_merge($this->mapAttachments($message->attachments), $content);
        }

        $mapped[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * Map an assistant message to Anthropic format.
     *
     * @param \Crustum\Ai\Messages\AssistantMessage|\Crustum\Ai\Messages\Message $message Assistant message
     * @param array<int, array<string, mixed>> $mapped Mapped messages
     * @return void
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$mapped): void
    {
        if ($message instanceof AssistantMessage && Value::filled($message->replayBlocks)) {
            $mapped[] = [
                'role' => 'assistant',
                'content' => $this->ensureToolInputIsObject($message->replayBlocks),
            ];

            return;
        }

        // Reasoning a step did not replay verbatim is dropped rather than rebuilt as a thinking block, which Anthropic rejects without the signature it issued.
        $content = [];
        $hasToolCalls = $message instanceof AssistantMessage && !$message->toolCalls->isEmpty();

        if (Value::filled($message->content)) {
            $content[] = [
                'type' => 'text',
                'text' => $message->content,
            ];
        }

        if ($hasToolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => $toolCall->arguments ?: (object)[],
                ];
            }
        }

        if (Value::filled($content)) {
            $mapped[] = [
                'role' => 'assistant',
                'content' => $content,
            ];
        }
    }

    /**
     * Map a tool result message to Anthropic format.
     *
     * @param \Crustum\Ai\Messages\ToolResultMessage|\Crustum\Ai\Messages\Message $message Tool result message
     * @param array<int, array<string, mixed>> $mapped Mapped messages
     * @return void
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$mapped): void
    {
        if (!$message instanceof ToolResultMessage) {
            return;
        }

        $content = [];

        foreach ($message->toolResults as $toolResult) {
            $content[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolResult->id,
                'content' => $toolResult->text(),
            ];
        }

        $mapped[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * Ensure tool_use and server_tool_use content blocks encode empty input as an object for replay.
     *
     * @param array<int, array<string, mixed>> $content Content blocks
     * @return array<int, array<string, mixed>>
     */
    protected function ensureToolInputIsObject(array $content): array
    {
        return array_map(function (array $block): array {
            if (in_array($block['type'] ?? '', ['tool_use', 'server_tool_use'], true)) {
                $block['input'] = (object)($block['input'] ?? []);
            }

            return $block;
        }, $content);
    }
}
