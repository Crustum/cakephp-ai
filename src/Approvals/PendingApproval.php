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
     * Determine whether a stored tool call is still awaiting an approval decision.
     *
     * @param array<string, mixed> $toolCall Stored tool call
     */
    public static function isPending(array $toolCall): bool
    {
        return array_key_exists('approval_reason', $toolCall) && !static::isAnswered($toolCall);
    }

    /**
     * Determine whether a stored tool call has been answered by its tool.
     *
     * @param array<string, mixed> $toolCall Stored tool call
     */
    public static function isAnswered(array $toolCall): bool
    {
        return array_key_exists('result', $toolCall);
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
