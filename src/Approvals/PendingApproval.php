<?php
declare(strict_types=1);

namespace Crustum\Ai\Approvals;

/**
 * A pending tool call awaiting human approval.
 */
class PendingApproval
{
    /**
     * @param string $id Tool call identifier
     * @param string $tool Tool name
     * @param array<string, mixed> $arguments Tool call arguments
     * @param string|null $reason Optional approval reason
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tool,
        public readonly array $arguments,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * Get the instance as an array.
     *
     * @return array{id: string, tool: string, arguments: array<string, mixed>, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'reason' => $this->reason,
        ];
    }
}
