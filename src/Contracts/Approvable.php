<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

use Crustum\Ai\Approvals\Approval;
use Crustum\Ai\Tools\Request;

/**
 * Contract for tools that may require human approval before execution.
 */
interface Approvable
{
    /**
     * Indicate that the tool requires approval before execution.
     *
     * @param string|null $reason Optional approval reason
     */
    public function requireApproval(?string $reason = null): static;

    /**
     * Indicate that the tool may execute without approval.
     */
    public function withoutApproval(): static;

    /**
     * Determine whether the tool should request approval for the given request.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request
     * @return \Crustum\Ai\Approvals\Approval|null
     */
    public function shouldRequestApproval(Request $request): ?Approval;
}
