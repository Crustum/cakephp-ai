<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

/**
 * Conversation store that verifies participant ownership.
 */
interface VerifiesConversationOwnership
{
    /**
     * Determine whether the given conversation was stored for the given participant.
     *
     * @param string $conversationId Conversation identifier
     * @param string|null $participantType Participant type
     * @param string|int|null $participantId Participant identifier
     */
    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool;
}
