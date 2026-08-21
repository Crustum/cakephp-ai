<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Collection\CollectionInterface;
use Closure;
use Crustum\Ai\Ai;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Model\Entity\Conversation;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Trait\RemembersConversationsTrait;
use Crustum\Ai\Utility\Reflection;
use Crustum\Ai\Utility\Value;

/**
 * Resumes paused tool-approval runs for the provider's text gateway.
 */
trait ResumesToolApprovalsTrait
{
    /**
     * Get the tool approval to resume with, unless the agent's gateway is faked.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return array<string, \Crustum\Ai\Approvals\Decision>|null
     */
    protected function resumableApprovalFor(AgentPrompt $prompt): ?array
    {
        return $this->resumesAgainstRealGateway($prompt) ? $prompt->approvalDecisions->all() : null;
    }

    /**
     * Replace another provider's raw paused-turn replay state with its generic mapping, since raw blocks are only valid verbatim on the provider that produced them.
     *
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @return array<int, \Crustum\Ai\Messages\Message>
     */
    protected function withoutForeignProviderContentBlocks(array $messages): array
    {
        return array_map(function (Message $message): Message {
            if (
                $message instanceof AssistantMessage
                && Value::filled($message->providerContentBlocks)
                && $message->providerContentBlocksProvider !== null
                && $message->providerContentBlocksProvider !== $this->name()
            ) {
                return new AssistantMessage($message->content, $message->toolCalls);
            }

            return $message;
        }, $messages);
    }

    /**
     * Determine whether the prompt is a resume that runs tools against the real (non-faked) gateway.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return bool
     */
    protected function resumesAgainstRealGateway(AgentPrompt $prompt): bool
    {
        return $prompt->hasApprovalDecisions() && !Ai::manager()->hasFakeGatewayFor($prompt->agent::class);
    }

    /**
     * Get a callback that captures a resume's resolved approval results, also durably recording them when the store supports it.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param \Cake\Collection\CollectionInterface|null $resolvedApprovalResults Reference populated with the resolved results
     * @return \Closure|null
     */
    protected function approvalResultRecorderFor(AgentPrompt $prompt, ?CollectionInterface &$resolvedApprovalResults): ?Closure
    {
        if (!$this->resumesAgainstRealGateway($prompt)) {
            return null;
        }

        $storeRecorder = $this->storeApprovalResultRecorderFor($prompt);

        return function (array $toolResults) use ($storeRecorder, &$resolvedApprovalResults): void {
            $resolvedApprovalResults = collection($toolResults);

            if ($storeRecorder !== null) {
                $storeRecorder($toolResults);
            }
        };
    }

    /**
     * Get a callback that durably records resolved approval results before the run continues, if the store supports it.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return \Closure|null
     */
    protected function storeApprovalResultRecorderFor(AgentPrompt $prompt): ?Closure
    {
        $agent = $prompt->agent;

        if (!in_array(RemembersConversationsTrait::class, Reflection::classUsesRecursive($agent), true)) {
            return null;
        }

        /** @var \Crustum\Ai\Contracts\Agent&\Crustum\Ai\Contracts\RemembersConversations $agent */
        if ($agent->currentConversation() === null) {
            return null;
        }

        $store = Ai::manager()->conversationStore();

        $conversationId = $agent->currentConversation();
        $participant = $agent->conversationParticipant();
        $participantType = $participant === null ? null : Conversation::participantType($participant);
        $participantId = $participant === null ? null : Conversation::participantKey($participant);

        return fn(array $toolResults) => $store->storeApprovalResults(
            $conversationId,
            $participantType,
            $participantId,
            $toolResults,
        );
    }
}
