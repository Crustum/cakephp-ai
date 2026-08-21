<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Providers\TextProvider;

/**
 * Agent Prompt Class
 *
 * Represents a prompt for an AI agent with attachments and configuration.
 */
class AgentPrompt extends Prompt
{
    /**
     * The agent instance.
     */
    public readonly Agent $agent;

    /**
     * The prompt attachments.
     */
    public readonly CollectionInterface $attachments;

    /**
     * The timeout in seconds.
     */
    public readonly ?int $timeout;

    /**
     * The invocation ID.
     */
    public readonly ?string $invocationId;

    /**
     * The invocation ID of the run that delegated this run.
     */
    public readonly ?string $parentInvocationId;

    /**
     * The tool invocation ID of the tool call that delegated this run.
     */
    public readonly ?string $parentToolInvocationId;

    /**
     * Whether the caller has run out of providers to retry this prompt against.
     */
    protected readonly bool $isFinalAttempt;

    /**
     * Create a new agent prompt instance.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent instance
     * @param string $prompt The prompt text
     * @param \Cake\Collection\CollectionInterface|array $attachments The attachments
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider The AI provider
     * @param string $model The model identifier
     * @param int|null $timeout The timeout in seconds
     * @param string|null $invocationId The invocation ID
     * @param \Crustum\Ai\Approvals\Decisions|null $approvalDecisions Tool approval decisions to resume with
     * @param string|null $parentInvocationId The parent run invocation ID
     * @param string|null $parentToolInvocationId The parent tool invocation ID
     * @param bool $isFinalAttempt Whether the caller has run out of providers to retry against
     */
    public function __construct(
        Agent $agent,
        string $prompt,
        CollectionInterface|array $attachments,
        TextProvider $provider,
        string $model,
        ?int $timeout = null,
        ?string $invocationId = null,
        ?Decisions $approvalDecisions = null,
        ?string $parentInvocationId = null,
        ?string $parentToolInvocationId = null,
        bool $isFinalAttempt = true,
    ) {
        parent::__construct($prompt, $provider, $model, $approvalDecisions);

        $this->agent = $agent;
        $this->attachments = is_array($attachments) ? collection($attachments) : $attachments;
        $this->timeout = $timeout;
        $this->invocationId = $invocationId;
        $this->parentInvocationId = $parentInvocationId;
        $this->parentToolInvocationId = $parentToolInvocationId;
        $this->isFinalAttempt = $isFinalAttempt;
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
     * Prepend to the prompt and return a new prompt instance.
     *
     * @param string $prompt The text to prepend
     * @return self
     */
    public function prepend(string $prompt): AgentPrompt
    {
        return $this->revise($prompt . PHP_EOL . PHP_EOL . $this->prompt);
    }

    /**
     * Append to the prompt and return a new prompt instance.
     *
     * @param string $prompt The text to append
     * @return self
     */
    public function append(string $prompt): AgentPrompt
    {
        return $this->revise($this->prompt . PHP_EOL . PHP_EOL . $prompt);
    }

    /**
     * Revise the prompt and return a new prompt instance.
     *
     * @param string $prompt The new prompt text
     * @param \Cake\Collection\CollectionInterface|array|null $attachments The new attachments
     * @return self
     */
    public function revise(string $prompt, CollectionInterface|array|null $attachments = null): AgentPrompt
    {
        if ($this->hasApprovalDecisions()) {
            return $this;
        }

        if (is_array($attachments)) {
            $attachments = collection($attachments);
        }

        return new self(
            $this->agent,
            $prompt,
            $attachments ?? $this->attachments,
            $this->provider,
            $this->model,
            $this->timeout,
            $this->invocationId,
            $this->approvalDecisions,
            $this->parentInvocationId,
            $this->parentToolInvocationId,
            $this->isFinalAttempt,
        );
    }

    /**
     * Add new attachment to the prompt, returning a new prompt instance.
     *
     * @param \Cake\Collection\CollectionInterface|array $attachments The attachments
     * @return self
     */
    public function withAttachments(CollectionInterface|array $attachments): AgentPrompt
    {
        return $this->revise($this->prompt, $attachments);
    }

    /**
     * Get the provider instance.
     *
     * @return \Crustum\Ai\Contracts\Providers\TextProvider
     */
    public function provider(): TextProvider
    {
        return $this->provider;
    }

    /**
     * Determine whether the caller has run out of providers to retry this prompt against.
     *
     * @return bool
     */
    public function isFinalAttempt(): bool
    {
        return $this->isFinalAttempt;
    }
}
