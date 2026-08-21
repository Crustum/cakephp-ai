<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Utility\Value;

/**
 * Maps messages to Gemini contents format.
 */
trait MapsMessagesTrait
{
    /**
     * Map the given messages to Gemini contents format.
     *
     * @param array<int, mixed> $messages Conversation messages
     * @return array<int, array<string, mixed>>
     */
    protected function mapMessagesToContents(array $messages): array
    {
        $contents = [];

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $contents),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $contents),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $contents),
            };
        }

        return $contents;
    }

    /**
     * Map a user message to Gemini format.
     *
     * @param \Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Messages\Message $message User message
     * @param array<int, array<string, mixed>> $contents Contents
     * @return void
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$contents): void
    {
        $parts = [['text' => $message->content]];

        if ($message instanceof UserMessage && !$message->attachments->isEmpty()) {
            $parts = array_merge($parts, $this->mapAttachments($message->attachments));
        }

        $contents[] = [
            'role' => 'user',
            'parts' => $parts,
        ];
    }

    /**
     * Map an assistant message to Gemini format.
     *
     * @param \Crustum\Ai\Messages\AssistantMessage|\Crustum\Ai\Messages\Message $message Assistant message
     * @param array<int, array<string, mixed>> $contents Contents
     * @return void
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$contents): void
    {
        if ($message instanceof AssistantMessage && Value::filled($message->providerContentBlocks)) {
            $contents[] = [
                'role' => 'model',
                'parts' => $message->providerContentBlocks,
            ];

            return;
        }

        $parts = [];

        if (Value::filled($message->content)) {
            $parts[] = ['text' => $message->content];
        }

        if ($message instanceof AssistantMessage && !$message->toolCalls->isEmpty()) {
            foreach ($message->toolCalls as $toolCall) {
                $functionCall = ['name' => $toolCall->name];

                if (Value::filled($toolCall->arguments)) {
                    $functionCall['args'] = $toolCall->arguments;
                }

                $parts[] = ['functionCall' => $functionCall];
            }
        }

        if (Value::filled($parts)) {
            $contents[] = [
                'role' => 'model',
                'parts' => $parts,
            ];
        }
    }

    /**
     * Map a tool result message to Gemini format.
     *
     * @param \Crustum\Ai\Messages\ToolResultMessage|\Crustum\Ai\Messages\Message $message Tool result message
     * @param array<int, array<string, mixed>> $contents Contents
     * @return void
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$contents): void
    {
        if (!$message instanceof ToolResultMessage) {
            return;
        }

        $parts = $this->buildFunctionResponseParts($message->toolResults->toList());

        if (Value::filled($parts)) {
            $contents[] = [
                'role' => 'user',
                'parts' => $parts,
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
