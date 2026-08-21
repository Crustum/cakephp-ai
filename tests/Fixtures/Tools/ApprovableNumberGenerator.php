<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Tools;

use Crustum\Ai\Approvals\Approval;
use Crustum\Ai\Contracts\Approvable;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Tools\Request;
use Crustum\Ai\Trait\InteractsWithApprovalsTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;

class ApprovableNumberGenerator implements Approvable, Tool
{
    use InteractsWithApprovalsTrait {
        shouldRequestApproval as protected traitShouldRequestApproval;
    }

    public static int $invocations = 0;

    public static int $approvalChecks = 0;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Generates a number, but requires human approval first.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        static::$invocations++;

        return '72019';
    }

    /**
     * Get the tool's schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema Schema builder.
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Determine whether the tool should request approval for the given request.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request.
     * @return \Crustum\Ai\Approvals\Approval|null
     */
    public function shouldRequestApproval(Request $request): ?Approval
    {
        static::$approvalChecks++;

        return $this->traitShouldRequestApproval($request);
    }
}
