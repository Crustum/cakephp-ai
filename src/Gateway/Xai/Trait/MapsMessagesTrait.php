<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Xai\Trait;

use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Utility\Value;

/**
 * Maps messages to xAI Responses API input format.
 */
trait MapsMessagesTrait
{
    /**
     * Map the given messages to xAI Responses API input format.
     *
     * @param array<int, mixed> $messages Conversation messages
     * @param string|null $instructions System instructions
     * @return array<int, array<string, mixed>>
     */
    protected function mapMessagesToInput(array $messages, ?string $instructions = null): array
    {
        $input = [];

        if (Value::filled($instructions)) {
            $input[] = [
                'role' => 'system',
                'content' => $instructions,
            ];
        }

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $input),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $input),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $input),
            };
        }

        return $input;
    }

    /**
     * Map a user message to xAI format.
     *
     * @param \Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Messages\Message $message User message
     * @param array<int, array<string, mixed>> $input Input items
     * @return void
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$input): void
    {
        $content = [
            ['type' => 'input_text', 'text' => $message->content],
        ];

        if ($message instanceof UserMessage && !$message->attachments->isEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments));
        }

        $input[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * Map an assistant message to xAI format.
     *
     * @param \Crustum\Ai\Messages\AssistantMessage|\Crustum\Ai\Messages\Message $message Assistant message
     * @param array<int, array<string, mixed>> $input Input items
     * @return void
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$input): void
    {
        if ($message instanceof AssistantMessage && !$message->toolCalls->isEmpty()) {
            $reasoningBlocks = [];

            foreach ($message->toolCalls as $toolCall) {
                if ($toolCall->reasoningId === null) {
                    continue;
                }

                $reasoningBlocks[$toolCall->reasoningId] = [
                    'type' => 'reasoning',
                    'id' => $toolCall->reasoningId,
                    'summary' => $toolCall->reasoningSummary ?? [],
                ];
            }

            foreach (array_values($reasoningBlocks) as $reasoningBlock) {
                $input[] = $reasoningBlock;

                foreach ($message->toolCalls as $toolCall) {
                    if ($toolCall->reasoningId !== $reasoningBlock['id']) {
                        continue;
                    }

                    $input[] = $this->buildFunctionCallItem($toolCall);
                }
            }

            foreach ($message->toolCalls as $toolCall) {
                if ($toolCall->reasoningId !== null) {
                    continue;
                }

                $input[] = $this->buildFunctionCallItem($toolCall);
            }
        }

        if (Value::filled($message->content)) {
            $input[] = [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => $message->content,
                    ],
                ],
            ];
        }
    }

    /**
     * Build an xAI function_call input item from a tool call.
     *
     * @param \Crustum\Ai\Responses\Data\ToolCall $toolCall Tool call
     * @return array<string, mixed>
     */
    protected function buildFunctionCallItem(ToolCall $toolCall): array
    {
        return [
            'id' => $toolCall->id,
            'call_id' => $toolCall->resultId,
            'type' => 'function_call',
            'name' => $toolCall->name,
            'arguments' => json_encode($toolCall->arguments ?: (object)[]),
        ];
    }

    /**
     * Map a tool result message to xAI format.
     *
     * @param \Crustum\Ai\Messages\ToolResultMessage|\Crustum\Ai\Messages\Message $message Tool result message
     * @param array<int, array<string, mixed>> $input Input items
     * @return void
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$input): void
    {
        if (!$message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $input[] = [
                'type' => 'function_call_output',
                'call_id' => $toolResult->resultId,
                'output' => $this->serializeToolResultOutput($toolResult->result),
            ];
        }
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

        return is_array($output) ? json_encode($output) : strval($output);
    }
}
