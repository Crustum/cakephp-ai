<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenRouter\Trait;

use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionMessagesTrait;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Utility\Value;

/**
 * Maps OpenRouter chat completion messages.
 */
trait MapsMessagesTrait
{
    use MapsChatCompletionMessagesTrait {
        mapAssistantMessage as mapChatCompletionAssistantMessage;
    }

    /**
     * Map an assistant message, replaying its reasoning details with its tool calls.
     *
     * @param \Crustum\Ai\Messages\AssistantMessage|\Crustum\Ai\Messages\Message $message Assistant message
     * @param array<int|string, array<string, mixed>> $chatMessages Chat messages
     * @return void
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): void
    {
        $this->mapChatCompletionAssistantMessage($message, $chatMessages);

        if (
            $message instanceof AssistantMessage
            && !$message->toolCalls->isEmpty()
            && Value::filled($message->replayBlocks)
        ) {
            $chatMessages[array_key_last($chatMessages)]['reasoning_details'] = $message->replayBlocks;
        }
    }
}
