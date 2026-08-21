<?php
declare(strict_types=1);

namespace Crustum\Ai\Approvals;

/**
 * Typed map of tool-call approval decisions.
 */
class Decisions
{
    /**
     * @param array<string, \Crustum\Ai\Approvals\Decision> $decisions Decision map
     */
    private function __construct(protected array $decisions)
    {
    }

    /**
     * Create approval decisions from an ID-keyed decision map.
     *
     * @param array<string, mixed> $decisions Decision map
     */
    public static function from(array $decisions): self
    {
        return new self(Decision::normalize($decisions));
    }

    /**
     * Get all approval decisions.
     *
     * @return array<string, \Crustum\Ai\Approvals\Decision>
     */
    public function all(): array
    {
        return $this->decisions;
    }

    /**
     * Get the decision for the given tool call ID.
     *
     * @param string $toolCallId Tool call identifier
     * @return \Crustum\Ai\Approvals\Decision|null
     */
    public function get(string $toolCallId): ?Decision
    {
        return $this->decisions[$toolCallId] ?? null;
    }

    /**
     * Approve every tool call without an explicit decision.
     */
    public function approveRemaining(): self
    {
        return self::from([
            ...$this->decisions,
            '*' => Decision::approve(),
        ]);
    }

    /**
     * Reject every tool call without an explicit decision.
     *
     * @param string|null $result Optional rejection result text
     */
    public function rejectRemaining(?string $result = null): self
    {
        return self::from([
            ...$this->decisions,
            '*' => Decision::reject($result),
        ]);
    }
}
