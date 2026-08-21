<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Enums\Lab;

/**
 * Queued Agent Prompt Class
 *
 * Represents a queued prompt for an AI agent that will be processed later.
 */
class QueuedAgentPrompt
{
    /**
     * The prompt text.
     */
    public string $prompt;

    /**
     * Tool approval decisions to resume with.
     */
    public ?Decisions $approvalDecisions;

    /**
     * Create a new queued agent prompt instance.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent instance
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param \Cake\Collection\CollectionInterface|array<mixed> $attachments The attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     */
    public function __construct(
        public Agent $agent,
        Decisions|string $prompt,
        public CollectionInterface|array $attachments,
        public Lab|array|string|null $provider,
        public ?string $model,
    ) {
        $this->prompt = is_string($prompt) ? $prompt : '';
        $this->approvalDecisions = $prompt instanceof Decisions ? $prompt : null;

        if (is_array($attachments)) {
            $this->attachments = collection($attachments);
        }
    }

    /**
     * Determine if the prompt contains the given string.
     *
     * @param string $string The string to search for
     * @return bool
     */
    public function contains(string $string): bool
    {
        return str_contains($this->prompt, $string);
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
