<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Tools;

use Crustum\Ai\Approvals\Approval;
use Crustum\Ai\Contracts\Approvable;
use Crustum\Ai\Tools\Request;
use Crustum\Ai\Trait\InteractsWithApprovalsTrait;
use Override;

class TextGenerationLoopApprovableTool extends TextGenerationLoopCountingTool implements Approvable
{
    use InteractsWithApprovalsTrait;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $handledArguments = [];

    public ?string $approvalToolCallId = null;

    /**
     * Determine whether the tool needs approval for the given request.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request.
     * @return \Crustum\Ai\Approvals\Approval|bool
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $this->approvalToolCallId = $request->toolCallId();

        return Approval::required('Needs a human');
    }

    /**
     * Execute the tool.
     */
    #[Override]
    public function handle(Request $request): string
    {
        $this->calls++;
        $this->handledArguments[] = $request->all();

        return 'handled ' . $request['value'];
    }
}
