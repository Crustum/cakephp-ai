<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Contracts\Providers\TextProvider;

/**
 * Base Prompt Class
 *
 * Abstract base class for AI prompts.
 */
abstract class Prompt
{
    /**
     * Create a new prompt instance.
     *
     * @param string $prompt The prompt text
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider The AI provider
     * @param string $model The model identifier
     * @param \Crustum\Ai\Approvals\Decisions|null $approvalDecisions Tool approval decisions to resume with
     */
    public function __construct(
        public readonly string $prompt,
        public readonly TextProvider $provider,
        public readonly string $model,
        public readonly ?Decisions $approvalDecisions = null,
    ) {
    }

    /**
     * Determine whether the prompt has tool approval decisions.
     *
     * @return bool
     */
    public function hasApprovalDecisions(): bool
    {
        return $this->approvalDecisions instanceof Decisions;
    }
}
