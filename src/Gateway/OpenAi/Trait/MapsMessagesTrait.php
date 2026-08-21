<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAi\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Utility\Value;

/**
 * Maps messages to OpenAI Responses API input format.
 */
trait MapsMessagesTrait
{
    /**
     * Map the given messages to OpenAI Responses API input format.
     *
     * @param array<int, mixed> $messages Conversation messages
     * @param string|null $instructions System instructions
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<int, array<string, mixed>>
     */
    protected function mapMessagesToInput(array $messages, ?string $instructions, Provider $provider): array
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
                MessageRole::User => $this->mapUserMessage($message, $input, $provider),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $input),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $input),
            };
        }

        return $input;
    }

    /**
     * Map a user message to OpenAI format.
     *
     * @param \Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Messages\Message $message User message
     * @param array<int, array<string, mixed>> $input Input messages
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return void
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$input, Provider $provider): void
    {
        $content = [
            ['type' => 'input_text', 'text' => $message->content],
        ];

        if ($message instanceof UserMessage && !$message->attachments->isEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments, $provider));
        }

        $input[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * Map an assistant message to OpenAI format.
     *
     * @param \Crustum\Ai\Messages\AssistantMessage|\Crustum\Ai\Messages\Message $message Assistant message
     * @param array<int, mixed> $input Input messages
     * @return void
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$input): void
    {
        if ($message instanceof AssistantMessage && filled($message->providerContentBlocks)) {
            foreach ($message->providerContentBlocks as $block) {
                $input[] = $block;
            }

            return;
        }

        if ($message instanceof AssistantMessage && !$message->toolCalls->isEmpty()) {
            $reasoningBlocks = [];
            $seenReasoningIds = [];

            foreach ($message->toolCalls as $toolCall) {
                if (!Value::filled($toolCall->reasoningId)) {
                    continue;
                }

                if (isset($seenReasoningIds[$toolCall->reasoningId])) {
                    continue;
                }

                $seenReasoningIds[$toolCall->reasoningId] = true;

                $reasoningBlocks[] = array_filter([
                    'type' => 'reasoning',
                    'id' => $toolCall->reasoningId,
                    'summary' => $toolCall->reasoningSummary ?? [],
                    'encrypted_content' => $toolCall->reasoningEncryptedContent,
                ]);
            }

            foreach ($reasoningBlocks as $reasoningBlock) {
                $input[] = $reasoningBlock;

                foreach (
                    $message->toolCalls->filter(
                        fn($toolCall): bool => ($toolCall->reasoningId ?? null) === ($reasoningBlock['id'] ?? null),
                    ) as $toolCall
                ) {
                    $input[] = [
                        'id' => $toolCall->id,
                        'call_id' => $toolCall->resultId,
                        'type' => 'function_call',
                        'name' => $toolCall->name,
                        'arguments' => json_encode($toolCall->arguments ?: (object)[]),
                    ];
                }
            }

            foreach ($message->toolCalls->filter(fn($toolCall): bool => !Value::filled($toolCall->reasoningId)) as $toolCall) {
                $input[] = [
                    'id' => $toolCall->id,
                    'call_id' => $toolCall->resultId,
                    'type' => 'function_call',
                    'name' => $toolCall->name,
                    'arguments' => json_encode($toolCall->arguments ?: (object)[]),
                ];
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
     * Map a tool result message to OpenAI format.
     *
     * @param \Crustum\Ai\Messages\ToolResultMessage|\Crustum\Ai\Messages\Message $message Tool result message
     * @param array<int, array<string, mixed>> $input Input messages
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
}
