<?php
declare(strict_types=1);

namespace Crustum\Ai\Middleware;

use Cake\Core\Configure;
use Cake\Utility\Text;
use Closure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Model\Entity\Conversation;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Persists agent conversations through a conversation store.
 */
class RememberConversation
{
    /**
     * @param \Crustum\Ai\Contracts\ConversationStore $store Conversation store
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     */
    public function __construct(
        protected ConversationStore $store,
        protected TextProvider $provider,
    ) {
    }

    /**
     * Handle the incoming prompt.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param \Closure $next Next middleware callback
     */
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        return $next($prompt)->then(function (AgentResponse $response) use ($prompt): void {
            /** @var \Crustum\Ai\Contracts\Agent&\Crustum\Ai\Contracts\RemembersConversations $agent */
            $agent = $prompt->agent;

            if (!$this->shouldRemember($agent, $prompt, $response)) {
                return;
            }

            $participant = $agent->conversationParticipant();
            $participantType = $participant === null ? null : Conversation::participantType($participant);
            $participantId = $participant === null ? null : Conversation::participantKey($participant);

            if (!$agent->currentConversation()) {
                $conversationId = $this->store->storeConversation(
                    $participantType,
                    $participantId,
                    $this->generateTitle($prompt->prompt),
                );

                $agent->continue($conversationId, $participant);
            }

            if (!$prompt->hasApprovalDecisions()) {
                $this->store->storeUserMessage(
                    $agent->currentConversation(),
                    $participantType,
                    $participantId,
                    $prompt,
                );
            }

            $this->store->storeAssistantMessage(
                $agent->currentConversation(),
                $participantType,
                $participantId,
                $prompt,
                $response,
            );

            $response->withinConversation(
                $agent->currentConversation(),
                $participant,
            );
        });
    }

    /**
     * Determine whether this turn should be persisted.
     *
     * @param \Crustum\Ai\Contracts\Agent&\Crustum\Ai\Contracts\RemembersConversations $agent Agent instance
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response
     * @return bool
     */
    protected function shouldRemember(Agent $agent, AgentPrompt $prompt, AgentResponse $response): bool
    {
        if ($agent->hasConversationParticipant()) {
            return true;
        }

        if ($agent->currentConversation() !== null) {
            return true;
        }

        if ($response->hasPendingApprovals()) {
            return true;
        }

        return $prompt->hasApprovalDecisions();
    }

    /**
     * Generate a title for the conversation.
     *
     * @param string $prompt Prompt text
     * @return string
     */
    protected function generateTitle(string $prompt): string
    {
        if (!(bool)Configure::read('Ai.conversations.generate_title', true)) {
            return Text::truncate($prompt, 50);
        }

        try {
            $response = $this->provider->textGenerationLoop()->generate(
                $this->provider,
                $this->provider->cheapestTextModel(),
                'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. Respond with only the title, no quotes or punctuation.',
                [new UserMessage(Text::truncate($prompt, 500))],
            );

            return Text::truncate($response->text, 100);
        } catch (Throwable) {
            return Text::truncate($prompt, 100);
        }
    }
}
