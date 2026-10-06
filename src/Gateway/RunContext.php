<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Event\InvokingTool;
use Crustum\Ai\Event\StartingStep;
use Crustum\Ai\Event\StepCompleted;
use Crustum\Ai\Event\StepFailed;
use Crustum\Ai\Event\ToolFailed;
use Crustum\Ai\Event\ToolInvoked;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Step;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolResult;
use Throwable;

/**
 * Carries a run's identity and dispatches its step / tool events.
 */
class RunContext
{
    /**
     * @var array<int, \Crustum\Ai\Responses\Data\Step> Steps recorded so far.
     */
    protected array $steps = [];

    /**
     * Constructor.
     *
     * @param string $invocationId Run invocation identifier
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Provider instance
     * @param string $model Model name
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(
        public readonly string $invocationId,
        public readonly Agent $agent,
        public readonly TextProvider $provider,
        public readonly string $model,
        protected readonly EventManagerInterface $events,
    ) {
    }

    /**
     * Keep the step the model just produced, so a run that dies later can still be recorded as far as it got.
     *
     * @param \Crustum\Ai\Responses\Data\Step $step Completed step
     */
    public function recordStep(Step $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * Answer the step being worked on, one tool at a time, so a step that dies partway keeps the tools that ran.
     *
     * @param \Crustum\Ai\Responses\Data\ToolResult $result Tool result
     */
    public function recordToolResult(ToolResult $result): void
    {
        $step = array_key_last($this->steps);

        if ($step !== null) {
            $this->steps[$step]->toolResults[] = $result;
        }
    }

    /**
     * The response the run had built by the time it ended, however it ended.
     *
     * @return \Crustum\Ai\Responses\AgentResponse
     */
    public function recordedResponse(): AgentResponse
    {
        $last = $this->steps === [] ? null : $this->steps[array_key_last($this->steps)];

        $usage = new TextUsage();

        foreach ($this->steps as $step) {
            $usage = $usage->add($step->usage);
        }

        $response = new AgentResponse(
            $this->invocationId,
            $last === null ? '' : $last->text,
            $usage,
            $last === null ? new Meta($this->provider->name(), $this->model) : $last->meta,
        );

        $response->withSteps(collection($this->steps));

        return $response;
    }

    /**
     * Report that a generation step is about to start.
     *
     * @param \Crustum\Ai\Gateway\StepContext $step Step context
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Messages being sent for this step
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Resolved options for this step
     * @param string|null $model Model the step is requested against
     */
    public function startingStep(StepContext $step, array $messages, ?TextGenerationOptions $options, ?string $model = null): void
    {
        $this->events->dispatch(new StartingStep(
            $this->invocationId,
            $step->stepNumber,
            $this->agent,
            $this->provider,
            $model ?? $this->model,
            $step->isFinalStep,
            $messages,
            $options,
        ));
    }

    /**
     * Report that a generation step returned a response.
     *
     * @param \Crustum\Ai\Gateway\StepContext|null $step Step context
     * @param \Crustum\Ai\Gateway\StepResponse $response Step response
     * @param float $time Wall time spent in the provider call, in milliseconds
     * @param string|null $model Model the step was requested against
     */
    public function stepCompleted(?StepContext $step, StepResponse $response, float $time, ?string $model = null): void
    {
        if (!$step instanceof StepContext) {
            return;
        }

        $this->events->dispatch(new StepCompleted(
            $this->invocationId,
            $step->stepNumber,
            $this->agent,
            $this->provider,
            $model ?? $this->model,
            $step->isFinalStep,
            $response,
            $time,
        ));
    }

    /**
     * Report that a generation step ended without producing a response.
     *
     * @param \Crustum\Ai\Gateway\StepContext|null $step Step context
     * @param \Throwable $exception The failure
     * @param float $time Wall time spent in the provider call before it failed, in milliseconds
     * @param string|null $model Model the step was requested against
     */
    public function stepFailed(?StepContext $step, Throwable $exception, float $time, ?string $model = null): void
    {
        if (!$step instanceof StepContext) {
            return;
        }

        $this->events->dispatch(new StepFailed(
            $this->invocationId,
            $step->stepNumber,
            $this->agent,
            $this->provider,
            $model ?? $this->model,
            $step->isFinalStep,
            $exception,
            $time,
        ));
    }

    /**
     * Report that a tool is about to be invoked.
     *
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @param array<string, mixed> $arguments Tool arguments
     * @param string $toolInvocationId Tool invocation identifier
     */
    public function invokingTool(Tool $tool, array $arguments, string $toolInvocationId): void
    {
        $this->events->dispatch(new InvokingTool(
            $this->invocationId,
            $toolInvocationId,
            $this->agent,
            $tool,
            $arguments,
        ));
    }

    /**
     * Report that a tool returned a result.
     *
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @param array<string, mixed> $arguments Tool arguments
     * @param mixed $result Tool result
     * @param string $toolInvocationId Tool invocation identifier
     * @param float $time Wall time spent in the tool's handler, in milliseconds
     */
    public function toolInvoked(Tool $tool, array $arguments, mixed $result, string $toolInvocationId, float $time): void
    {
        $this->events->dispatch(new ToolInvoked(
            $this->invocationId,
            $toolInvocationId,
            $this->agent,
            $tool,
            $arguments,
            $result,
            $time,
        ));
    }

    /**
     * Report that a tool's handler threw.
     *
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @param array<string, mixed> $arguments Tool arguments
     * @param \Throwable $exception The failure
     * @param string $toolInvocationId Tool invocation identifier
     * @param float $time Wall time spent in the tool's handler before it threw, in milliseconds
     */
    public function toolFailed(Tool $tool, array $arguments, Throwable $exception, string $toolInvocationId, float $time): void
    {
        $this->events->dispatch(new ToolFailed(
            $this->invocationId,
            $toolInvocationId,
            $this->agent,
            $tool,
            $arguments,
            $exception,
            $time,
        ));
    }
}
