<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

use Cake\Collection\CollectionInterface;

/**
 * Stream event emitted when tool calls pause for human approval.
 */
class ToolApprovalRequest extends StreamEvent
{
    /**
     * @param string $id Event ID
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Approvals\PendingApproval> $pendingApprovals Pending approvals
     * @param int $timestamp Unix timestamp
     * @param array<int, array<string, mixed>> $providerContentBlocks Raw provider replay state for the paused turn; never serialized to clients
     */
    public function __construct(
        public string $id,
        public CollectionInterface $pendingApprovals,
        public int $timestamp,
        public array $providerContentBlocks = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'tool_approval_request',
            'approvals' => array_values(iterator_to_array($this->pendingApprovals->map(
                fn($approval) => $approval->toArray(),
            ))),
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @inheritDoc
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'tool-approval-request',
            'approvalId' => $this->id,
            'approvals' => array_values(iterator_to_array($this->pendingApprovals->map(
                fn($approval) => $approval->toArray(),
            ))),
        ];
    }
}
