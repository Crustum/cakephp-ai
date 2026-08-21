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
use Throwable;

/**
 * Carries a run's identity and dispatches its step / tool events.
 */
class RunContext
{
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
     * Report that a generation step is about to start.
     *
     * @param \Crustum\Ai\Gateway\StepContext $step Step context
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Messages being sent for this step
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Resolved options for this step
     */
    public function startingStep(StepContext $step, array $messages, ?TextGenerationOptions $options): void
    {
        $this->events->dispatch(new StartingStep(
            $this->invocationId,
            $step->stepNumber,
            $this->agent,
            $this->provider,
            $this->model,
            $step->isFinalStep,
            $messages,
            $options,
        ));
    }

    /**
     * Report that a generation step returned a response.
     *
     * @param \Crustum\Ai\Gateway\StepContext $step Step context
     * @param \Crustum\Ai\Gateway\StepResponse $response Step response
     * @param float $time Wall time spent in the provider call, in milliseconds
     */
    public function stepCompleted(StepContext $step, StepResponse $response, float $time): void
    {
        $this->events->dispatch(new StepCompleted(
            $this->invocationId,
            $step->stepNumber,
            $this->agent,
            $this->provider,
            $this->model,
            $step->isFinalStep,
            $response,
            $time,
        ));
    }

    /**
     * Report that a generation step ended without producing a response.
     *
     * @param \Crustum\Ai\Gateway\StepContext $step Step context
     * @param \Throwable $exception The failure
     * @param float $time Wall time spent in the provider call before it failed, in milliseconds
     */
    public function stepFailed(StepContext $step, Throwable $exception, float $time): void
    {
        $this->events->dispatch(new StepFailed(
            $this->invocationId,
            $step->stepNumber,
            $this->agent,
            $this->provider,
            $this->model,
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
