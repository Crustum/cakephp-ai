<?php
declare(strict_types=1);

namespace Crustum\Ai\Approvals;

use Crustum\Ai\Utility\Value;
use InvalidArgumentException;

/**
 * A single tool-call approval decision.
 */
class Decision
{
    /**
     * @param string $action Decision action (approve, reject, edit)
     * @param string|null $result Optional rejection result text
     * @param array<string, mixed>|null $arguments Edited tool arguments
     */
    private function __construct(
        public readonly string $action,
        public readonly ?string $result = null,
        public readonly ?array $arguments = null,
    ) {
    }

    /**
     * Approve the pending tool call.
     */
    public static function approve(): self
    {
        return new self('approve');
    }

    /**
     * Reject the pending tool call.
     *
     * @param string|null $result Optional rejection result text
     */
    public static function reject(?string $result = null): self
    {
        return new self('reject', result: Value::blank($result) ? null : $result);
    }

    /**
     * Approve the pending tool call with edited arguments.
     *
     * @param array<string, mixed> $arguments Edited tool arguments
     */
    public static function edit(array $arguments): self
    {
        return new self('edit', arguments: $arguments);
    }

    /**
     * A blanket approval for every pending tool call.
     *
     * @return \Crustum\Ai\Approvals\Decisions
     */
    public static function approveAll(): Decisions
    {
        return Decisions::from(['*' => self::approve()]);
    }

    /**
     * A blanket rejection for every pending tool call.
     *
     * @param string|null $result Optional rejection result text
     * @return \Crustum\Ai\Approvals\Decisions
     */
    public static function rejectAll(?string $result = null): Decisions
    {
        return Decisions::from(['*' => self::reject($result)]);
    }

    /**
     * Normalize an id-keyed decision map, accepting booleans as shorthand and a '*' wildcard for undecided calls.
     *
     * @param array<string, \Crustum\Ai\Approvals\Decision|bool|mixed> $decisions Decision map
     * @return array<string, \Crustum\Ai\Approvals\Decision>
     * @throws \InvalidArgumentException
     */
    public static function normalize(array $decisions): array
    {
        if ($decisions === []) {
            throw new InvalidArgumentException('Tool approval decisions may not be empty.');
        }

        $normalized = [];

        foreach ($decisions as $id => $decision) {
            $decision = match (true) {
                $decision === true => self::approve(),
                $decision === false => self::reject(),
                $decision instanceof self => $decision,
                default => throw new InvalidArgumentException('Tool approval decisions must be Decision instances or booleans.'),
            };

            if ($id === '*' && $decision->isEdited()) {
                throw new InvalidArgumentException('The wildcard decision may not use the edit action.');
            }

            $normalized[$id] = $decision;
        }

        return $normalized;
    }

    /**
     * Determine whether the tool call was approved.
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->action === 'approve';
    }

    /**
     * Determine whether the tool call was rejected.
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->action === 'reject';
    }

    /**
     * Determine whether the tool call arguments were edited.
     *
     * @return bool
     */
    public function isEdited(): bool
    {
        return $this->action === 'edit';
    }
}
