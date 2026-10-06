<?php
declare(strict_types=1);

namespace Crustum\Ai\Middleware;

use Cake\Core\Configure;
use Cake\Utility\Text;
use Closure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Crustum\Ai\Gateway\RunContext;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Model\Entity\Conversation;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Trait\RemembersConversationsTrait;
use Crustum\Ai\Utility\Reflection;
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
     * Determine whether the given agent remembers its conversations.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     */
    public static function appliesTo(Agent $agent): bool
    {
        return $agent instanceof RemembersConversationsContract
            || in_array(RemembersConversationsTrait::class, Reflection::classUsesRecursive($agent), true);
    }

    /**
     * Handle the incoming prompt.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param \Closure $next Next middleware callback
     */
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        /** @var \Crustum\Ai\Contracts\Agent&\Crustum\Ai\Contracts\RemembersConversations $agent */
        $agent = $prompt->agent;

        $pendingConversationId = $agent->currentConversation() === null
            ? Text::uuid()
            : null;

        try {
            $response = $next($prompt);
        } catch (Throwable $throwable) {
            $this->rememberFailedTurn($prompt, $throwable, $pendingConversationId);

            throw $throwable;
        }

        // A stream fails while it is being consumed, long after this pipeline returned, so it reports back here.
        if ($response instanceof StreamableAgentResponse) {
            $response->catch(fn(Throwable $throwable) => $this->rememberFailedTurn(
                $prompt,
                $throwable,
                $pendingConversationId,
                retryable: !$response->hasYielded(),
            ));
        }

        if ($pendingConversationId !== null && $response instanceof StreamableAgentResponse) {
            $response->withinConversation($pendingConversationId, $agent->conversationParticipant());
        }

        return $response->then(function (AgentResponse $completedResponse) use ($prompt, $agent, $pendingConversationId): void {
            if (!$this->shouldRemember($agent, $prompt, $completedResponse)) {
                if ($pendingConversationId !== null) {
                    $completedResponse->conversationId = null;
                    $completedResponse->conversationUser = null;
                }

                return;
            }

            $participant = $agent->conversationParticipant();

            $userMessageId = $this->openTurn($agent, $prompt, $pendingConversationId);

            [$participantType, $participantId] = $this->participantKeys($participant);

            $assistantMessageId = $this->store->storeAssistantMessage(
                $agent->currentConversation(),
                $participantType,
                $participantId,
                $prompt,
                $completedResponse,
            );

            $completedResponse->withinConversation(
                $agent->currentConversation(),
                $participant,
            )->withStoredMessages($userMessageId, $assistantMessageId);
        });
    }

    /**
     * Record the steps a run completed before it died, so the tools it already ran are not lost with it.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param \Throwable $exception The failure
     * @param string|null $pendingConversationId Pre-allocated conversation identifier
     * @param bool $retryable Whether the caller may retry against another provider
     * @return void
     */
    protected function rememberFailedTurn(AgentPrompt $prompt, Throwable $exception, ?string $pendingConversationId, bool $retryable = true): void
    {
        /** @var \Crustum\Ai\Contracts\Agent&\Crustum\Ai\Contracts\RemembersConversations $agent */
        $agent = $prompt->agent;

        // A failover retry writes the turn itself, so only the attempt the caller gives up on is recorded.
        if ($retryable && $prompt->willRetry($exception)) {
            return;
        }

        $context = $prompt->runContext();

        $prompt->setRunContext(null);

        if (!$context instanceof RunContext || !$this->shouldRememberTurn($agent, $prompt)) {
            return;
        }

        $response = $context->recordedResponse();

        // A resume that died before its first step still has to fail the row its approvals were written to.
        if ($response->steps->isEmpty() && !$prompt->hasApprovalDecisions()) {
            return;
        }

        $this->openTurn($agent, $prompt, $pendingConversationId);

        [$participantType, $participantId] = $this->participantKeys($agent->conversationParticipant());

        $this->store->storeAssistantMessage(
            $agent->currentConversation(),
            $participantType,
            $participantId,
            $prompt,
            $response,
            $exception,
        );
    }

    /**
     * Open the conversation this turn belongs to and record the prompt that started it.
     *
     * @param \Crustum\Ai\Contracts\Agent&\Crustum\Ai\Contracts\RemembersConversations $agent Agent instance
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param string|null $pendingConversationId Pre-allocated conversation identifier
     * @return string|null
     */
    protected function openTurn(Agent $agent, AgentPrompt $prompt, ?string $pendingConversationId): ?string
    {
        $participant = $agent->conversationParticipant();

        [$participantType, $participantId] = $this->participantKeys($participant);

        if ($pendingConversationId !== null || !$agent->currentConversation()) {
            $agent->continue($this->store->storeConversation(
                $participantType,
                $participantId,
                $this->generateTitle($prompt->prompt),
                $pendingConversationId,
            ), $participant);
        }

        // A resume continues the turn its decisions answer, so it adds no message of its own.
        return $prompt->hasApprovalDecisions() ? null : $this->store->storeUserMessage(
            $agent->currentConversation(),
            $participantType,
            $participantId,
            $agent::class,
            new UserMessage($prompt->prompt, $prompt->attachments),
        );
    }

    /**
     * Split a participant into the type and key it is stored under.
     *
     * @param object|null $participant Conversation participant
     * @return array{0: string|null, 1: string|int|null}
     */
    protected function participantKeys(?object $participant): array
    {
        return $participant === null
            ? [null, null]
            : [Conversation::participantType($participant), Conversation::participantKey($participant)];
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
        return $this->shouldRememberTurn($agent, $prompt)
            || $response->hasPendingApprovals();
    }

    /**
     * Determine whether this turn belongs to a conversation, however it ended.
     *
     * @param \Crustum\Ai\Contracts\Agent&\Crustum\Ai\Contracts\RemembersConversations $agent Agent instance
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @return bool
     */
    protected function shouldRememberTurn(Agent $agent, AgentPrompt $prompt): bool
    {
        if ($agent->hasConversationParticipant()) {
            return true;
        }

        if ($agent->currentConversation() !== null) {
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
