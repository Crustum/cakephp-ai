<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Crustum\Ai\Approvals\Approval;
use Crustum\Ai\Tools\Request;

/**
 * Default approval gating for tools that implement Approvable.
 */
trait InteractsWithApprovalsTrait
{
    protected Approval|bool|null $approvalRequirement = null;

    /**
     * Indicate that the tool requires approval before execution.
     *
     * @param string|null $reason Optional approval reason
     */
    public function requireApproval(?string $reason = null): static
    {
        $this->approvalRequirement = Approval::required($reason);

        return $this;
    }

    /**
     * Indicate that the tool may execute without approval.
     */
    public function withoutApproval(): static
    {
        $this->approvalRequirement = false;

        return $this;
    }

    /**
     * Determine whether the tool should request approval for the given request.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request
     * @return \Crustum\Ai\Approvals\Approval|null
     */
    public function shouldRequestApproval(Request $request): ?Approval
    {
        $result = $this->approvalRequirement ?? $this->needsApproval($request);

        return match (true) {
            $result === false => null,
            $result === true => Approval::required(),
            default => $result,
        };
    }

    /**
     * Determine whether the tool needs approval for the given request.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request
     * @return \Crustum\Ai\Approvals\Approval|bool
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return true;
    }
}
