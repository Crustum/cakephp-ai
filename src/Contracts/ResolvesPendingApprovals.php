<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

/**
 * Conversation store that exposes pending approvals.
 */
interface ResolvesPendingApprovals
{
    /**
     * Get the tool calls the given conversation's newest turn is still waiting on.
     *
     * @param string $conversationId Conversation identifier
     * @return list<\Crustum\Ai\Approvals\PendingApproval>
     */
    public function pendingApprovalsFor(string $conversationId): array;
}
