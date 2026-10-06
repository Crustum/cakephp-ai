<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Exception\FailoverableException;
use Crustum\Ai\Gateway\RunContext;
use Throwable;

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
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile>
     */
    public readonly CollectionInterface $attachments;

    /**
     * The ad-hoc message history to send ahead of the prompt.
     *
     * @var list<\Crustum\Ai\Messages\Message>|null
     */
    public readonly ?array $messages;

    /**
     * The tools available for this run, or null to use the tools the agent declares.
     *
     * @var array<int, \Crustum\Ai\Contracts\Agent|\Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool>|null
     */
    public readonly ?array $tools;

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
     * The context the run dispatched for this prompt records its steps on.
     */
    protected ?RunContext $runContext = null;

    /**
     * Create a new agent prompt instance.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent instance
     * @param string $prompt The prompt text
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile>|array<int, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile> $attachments The attachments
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider The AI provider
     * @param string $model The model identifier
     * @param int|null $timeout The timeout in seconds
     * @param string|null $invocationId The invocation ID
     * @param \Crustum\Ai\Approvals\Decisions|null $approvalDecisions Tool approval decisions to resume with
     * @param string|null $parentInvocationId The parent run invocation ID
     * @param string|null $parentToolInvocationId The parent tool invocation ID
     * @param bool $isFinalAttempt Whether the caller has run out of providers to retry against
     * @param list<\Crustum\Ai\Messages\Message>|null $messages Ad-hoc message history
     * @param array<int, \Crustum\Ai\Contracts\Agent|\Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool>|null $tools Runtime tool overrides
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
        ?array $messages = null,
        ?array $tools = null,
    ) {
        parent::__construct($prompt, $provider, $model, $approvalDecisions);

        $this->agent = $agent;
        $this->attachments = is_array($attachments) ? collection($attachments) : $attachments;
        $this->messages = $messages;
        $this->tools = $tools;
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
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile>|array<int, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile>|null $attachments The new attachments
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
            $this->messages,
            $this->tools,
        );
    }

    /**
     * Replace the tools for this run, returning a new prompt instance.
     *
     * @param iterable<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool|\Crustum\Ai\Contracts\Agent> $tools Runtime tools
     * @return self
     */
    public function withTools(iterable $tools): AgentPrompt
    {
        if ($this->hasApprovalDecisions()) {
            return $this;
        }

        return new self(
            $this->agent,
            $this->prompt,
            $this->attachments,
            $this->provider,
            $this->model,
            $this->timeout,
            $this->invocationId,
            $this->approvalDecisions,
            $this->parentInvocationId,
            $this->parentToolInvocationId,
            $this->isFinalAttempt,
            $this->messages,
            [...$tools],
        );
    }

    /**
     * Add new attachment to the prompt, returning a new prompt instance.
     *
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile>|array<int, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile> $attachments The attachments
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

    /**
     * Set the context the run dispatched for this prompt records its steps on.
     *
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return void
     */
    public function setRunContext(?RunContext $context): void
    {
        $this->runContext = $context;
    }

    /**
     * The context the run dispatched for this prompt is recording its steps on.
     *
     * @return \Crustum\Ai\Gateway\RunContext|null
     */
    public function runContext(): ?RunContext
    {
        return $this->runContext;
    }

    /**
     * Determine whether the caller will retry this prompt against another provider.
     *
     * @param \Throwable $exception The failure
     * @return bool
     */
    public function willRetry(Throwable $exception): bool
    {
        return $exception instanceof FailoverableException && !$this->isFinalAttempt;
    }
}
