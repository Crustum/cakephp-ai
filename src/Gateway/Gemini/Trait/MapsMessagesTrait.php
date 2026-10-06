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
 * Maps messages to Gemini interaction input steps.
 */
trait MapsMessagesTrait
{
    /**
     * Map the given messages to Gemini interaction input steps.
     *
     * @param array<int, mixed> $messages Conversation messages
     * @return array<int, array<string, mixed>>
     */
    protected function mapMessagesToInput(array $messages): array
    {
        /** @var array<int, array<string, mixed>> $input */
        $input = [];

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
     * Map a user message to a Gemini user input step.
     *
     * @param \Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Messages\Message $message User message
     * @param array<int, array<string, mixed>> $input Interaction input steps
     * @return void
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$input): void
    {
        // Gemini rejects a text block without text, so an attachment-only message sends none.
        $content = Value::filled($message->content) ? [['type' => 'text', 'text' => $message->content]] : [];

        if ($message instanceof UserMessage && !$message->attachments->isEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments));
        }

        $input[] = [
            'type' => 'user_input',
            'content' => $content,
        ];
    }

    /**
     * Map an assistant message to the Gemini steps that produced it.
     *
     * @param \Crustum\Ai\Messages\AssistantMessage|\Crustum\Ai\Messages\Message $message Assistant message
     * @param array<int, array<string, mixed>> $input Interaction input steps
     * @return void
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$input): void
    {
        // Gemini requires its own steps, thought steps included, replayed exactly as it returned them.
        if ($message instanceof AssistantMessage && Value::filled($message->replayBlocks)) {
            /** @var array<int, array<string, mixed>> $replayBlocks */
            $replayBlocks = $message->replayBlocks;

            foreach ($replayBlocks as $step) {
                // Gemini rejects the empty array PHP decodes an argument-less call's object into.
                $input[] = isset($step['arguments'])
                    ? [...$step, 'arguments' => (object)$step['arguments']]
                    : $step;
            }

            return;
        }

        if (Value::filled($message->content)) {
            $input[] = [
                'type' => 'model_output',
                'content' => [['type' => 'text', 'text' => $message->content]],
            ];
        }

        if ($message instanceof AssistantMessage) {
            // A signature never rides on a function call, so a persisted turn rebuilds the thought step that held it.
            $signature = $message->toolCalls->first()?->thoughtSignature;

            if (Value::filled($signature)) {
                $input[] = ['type' => 'thought', 'signature' => $signature];
            }

            foreach ($message->toolCalls as $toolCall) {
                $input[] = [
                    'type' => 'function_call',
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'arguments' => (object)$toolCall->arguments,
                ];
            }
        }
    }

    /**
     * Map a tool result message to Gemini function result steps.
     *
     * @param \Crustum\Ai\Messages\ToolResultMessage|\Crustum\Ai\Messages\Message $message Tool result message
     * @param array<int, array<string, mixed>> $input Interaction input steps
     * @return void
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$input): void
    {
        if (!$message instanceof ToolResultMessage) {
            return;
        }

        foreach ($this->buildFunctionResultSteps($message->toolResults->toList()) as $step) {
            $input[] = $step;
        }
    }
}
