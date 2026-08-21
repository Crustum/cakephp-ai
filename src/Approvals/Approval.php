<?php
declare(strict_types=1);

namespace Crustum\Ai\Approvals;

/**
 * Value object representing a required tool approval.
 */
class Approval
{
    /**
     * @param string|null $reason Optional reason the tool requires approval
     */
    public function __construct(public readonly ?string $reason = null)
    {
    }

    /**
     * Create a required approval.
     *
     * @param string|null $reason Optional reason the tool requires approval
     */
    public static function required(?string $reason = null): self
    {
        return new self($reason);
    }
}
