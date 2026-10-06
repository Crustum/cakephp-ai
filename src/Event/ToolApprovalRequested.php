<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Contracts\Agent;

/**
 * Dispatched when an agent pauses for tool approval.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Contracts\Agent>
 */
class ToolApprovalRequested extends AiEvent
{
    /**
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Approvals\PendingApproval> $pendingApprovals Pending approvals
     * @param string|null $conversationId Conversation identifier
     * @param object|null $conversationUser Conversation participant
     */
    public function __construct(
        public string $invocationId,
        public Agent $agent,
        public CollectionInterface $pendingApprovals,
        public ?string $conversationId = null,
        public ?object $conversationUser = null,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'agent' => $agent,
            'pendingApprovals' => $pendingApprovals,
            'conversationId' => $conversationId,
            'conversationUser' => $conversationUser,
        ], $agent);
    }
}
