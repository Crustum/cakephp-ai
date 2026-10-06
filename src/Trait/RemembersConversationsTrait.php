<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Crustum\Ai\Ai;
use Crustum\Ai\Model\Entity\Conversation;

/**
 * Remembers conversations for conversational agents.
 */
trait RemembersConversationsTrait
{
    protected ?string $conversationId = null;

    protected ?object $conversationUser = null;

    /**
     * Start a new conversation for the given participant.
     *
     * @param object $participant Conversation participant
     */
    public function forParticipant(object $participant): static
    {
        $this->conversationId = null;
        $this->conversationUser = $participant;

        return $this;
    }

    /**
     * Start a new conversation for the given user.
     *
     * @param object $user Conversation participant
     */
    public function forUser(object $user): static
    {
        return $this->forParticipant($user);
    }

    /**
     * Continue an existing conversation, optionally as the given user.
     *
     * @param string $conversationId Conversation ID
     * @param object|null $as Conversation participant
     */
    public function continue(string $conversationId, ?object $as = null): static
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $as;

        return $this;
    }

    /**
     * Continue the given conversation for the participant, or start a new one when there is none.
     *
     * @param string|null $conversationId Conversation ID
     * @param object $as Conversation participant
     */
    public function continueOrStart(?string $conversationId, object $as): static
    {
        return $conversationId === null
            ? $this->forParticipant($as)
            : $this->continue($conversationId, as: $as);
    }

    /**
     * Continue the given user's last conversation with this agent.
     *
     * @param object $as Conversation participant
     */
    public function continueLastConversation(object $as): static
    {
        $this->conversationUser = $as;

        $this->conversationId = Ai::manager()->conversationStore()
            ->latestConversationId(
                Conversation::participantType($as),
                Conversation::participantKey($as),
                static::class,
            );

        return $this;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return iterable<int, mixed>
     */
    public function messages(): iterable
    {
        if ($this->conversationId === null) {
            return [];
        }

        return Ai::manager()->conversationStore()
            ->getLatestConversationMessages(
                $this->conversationId,
                $this->maxConversationMessages(),
            )
            ->toList();
    }

    /**
     * Get the maximum number of conversation messages to include in context.
     *
     * @return int
     */
    protected function maxConversationMessages(): int
    {
        return 100;
    }

    /**
     * Get the UUID for the current conversation, if applicable.
     */
    public function currentConversation(): ?string
    {
        return $this->conversationId;
    }

    /**
     * Determine if the conversation has a participant and is thus being remembered.
     *
     * @return bool
     */
    public function hasConversationParticipant(): bool
    {
        return $this->conversationUser !== null;
    }

    /**
     * Get the user having the current conversation.
     */
    public function conversationParticipant(): ?object
    {
        return $this->conversationUser;
    }
}
