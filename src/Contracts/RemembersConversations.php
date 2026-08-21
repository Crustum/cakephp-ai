<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

/**
 * Interface for agents that remember conversations.
 *
 * Provides conversation continuity across multiple interactions
 * and participant tracking capabilities.
 */
interface RemembersConversations extends Conversational
{
    /**
     * Start a new conversation for the given participant.
     *
     * @param object $participant Conversation participant
     */
    public function forParticipant(object $participant): static;

    /**
     * Start a new conversation for the given user.
     *
     * @param object $user Conversation participant
     */
    public function forUser(object $user): static;

    /**
     * Continue an existing conversation, optionally as the given user.
     *
     * @param string $conversationId Conversation ID
     * @param object|null $as Conversation participant
     */
    public function continue(string $conversationId, ?object $as = null): static;

    /**
     * Continue the latest conversation as the given user.
     *
     * @param object $as Conversation participant
     */
    public function continueLastConversation(object $as): static;

    /**
     * Determine if the conversation has a participant and is thus being remembered.
     *
     * @return bool
     */
    public function hasConversationParticipant(): bool;

    /**
     * Get the user having the current conversation.
     */
    public function conversationParticipant(): ?object;

    /**
     * Get the UUID for the current conversation, if applicable.
     */
    public function currentConversation(): ?string;
}
