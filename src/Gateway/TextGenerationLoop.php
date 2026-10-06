<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Cake\Utility\Text;
use Closure;
use Crustum\Ai\Approvals\Approval;
use Crustum\Ai\Approvals\ApprovalMismatchException;
use Crustum\Ai\Approvals\Decision;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Attributes\RepairToolCalls;
use Crustum\Ai\Contracts\Approvable;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\HasMiddleware;
use Crustum\Ai\Contracts\Providers\SupportsCodeExecution;
use Crustum\Ai\Contracts\Providers\SupportsFileSearch;
use Crustum\Ai\Contracts\Providers\SupportsToolSearch;
use Crustum\Ai\Contracts\Providers\SupportsWebFetch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Exception\NoSuchToolException;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Gateway\Trait\HandlesToolApprovalsTrait;
use Crustum\Ai\Gateway\Trait\InvokesToolsTrait;
use Crustum\Ai\Gateway\Trait\MeasuresDurationTrait;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\PendingStep;
use Crustum\Ai\Providers\Tools\CodeExecution;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Providers\Tools\ProviderTool;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Providers\Tools\WebFetch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Step;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Responses\StructuredTextResponse;
use Crustum\Ai\Responses\TextResponse;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Streaming\Event\ToolResult as ToolResultEvent;
use Crustum\Ai\Tools\AgentTool;
use Crustum\Ai\Tools\Request;
use Crustum\Ai\Tools\ToolNameResolver;
use Crustum\Ai\Trait\JoinsReasoningTrait;
use Crustum\Ai\Utility\Value;
use Generator;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Text Generation Loop
 *
 * Handles the multi-step text generation loop with tool calling support.
 * Manages conversation flow, tool invocation, human-in-the-loop approvals
 * and response building.
 */
class TextGenerationLoop
{
    use HandlesToolApprovalsTrait;
    use InvokesToolsTrait;
    use JoinsReasoningTrait;
    use MeasuresDurationTrait;

    /**
     * The characters a tool must add before its unfinished output is reported again.
     */
    private const PRELIMINARY_OUTPUT_BYTES = 240;

    /**
     * Whether unknown local tool calls should be repaired instead of failing the run.
     */
    private bool $repairsToolCalls = false;

    /**
     * The default ceiling for the derived step budget when it is not set explicitly.
     */
    protected const DEFAULT_MAX_STEPS = 25;

    /**
     * Constructor
     *
     * @param \Crustum\Ai\Contracts\Gateway\StepTextGateway $gateway Gateway for step-based text generation
     */
    public function __construct(protected StepTextGateway $gateway)
    {
    }

    /**
     * Generate text through a multi-step conversation loop.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider instance
     * @param string $model Model identifier
     * @param string|null $instructions System instructions
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Initial messages
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param array<string, mixed>|null $schema Response schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param int|null $timeout Timeout in seconds
     * @param array<string, \Crustum\Ai\Approvals\Decision>|null $approval Approval decisions keyed by tool call id
     * @param \Closure|null $recordApprovalResults Callback invoked with resolved approval tool results
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return \Crustum\Ai\Responses\TextResponse
     */
    public function generate(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?array $approval = null,
        ?Closure $recordApprovalResults = null,
        ?RunContext $context = null,
    ): TextResponse {
        $this->ensureSingleToolSearch($tools);

        $tools = $this->toolsSupportedBy($provider, $tools);

        $middleware = $this->middlewareFor($options);
        $steps = collection([]);
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $previous = null;
        $accumulatedUsage = new TextUsage();
        $lastResult = null;

        if ($approval !== null) {
            $resumption = $this->resumeFromApproval($approval, $messages, $tools, null, $context);

            $allMessages = $resumption->messages;
            $newMessages = $resumption->newMessages;

            if ($recordApprovalResults instanceof Closure) {
                $recordApprovalResults($resumption->results);
            }

            if (!$resumption->shouldContinue) {
                return (new TextResponse('', new TextUsage(), new Meta($provider->name(), $model)))
                    ->withMessages(collection($newMessages));
            }
        } else {
            $allMessages = $this->settleAbandonedToolCalls($messages);
            $newMessages = [];
        }

        for ($step = 0; $step < $maxSteps; $step++) {
            $pending = new PendingStep(
                number: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                provider: $provider->name(),
                model: $model,
                instructions: $instructions,
                messages: $allMessages,
                tools: $tools,
                schema: $schema,
                options: $options?->forStep($step),
                steps: $steps->toList(),
                usage: $accumulatedUsage,
                timeout: $timeout,
                invocationId: $context?->invocationId,
            );

            // Held by reference because a short-circuiting middleware returns a result that is not the attempt.
            $attempt = null;

            try {
                $lastResult = $this->runStep($pending, $middleware, function (PendingStep $step) use ($provider, $previous, $context, $allMessages, $continuationToken, &$attempt): StepResult {
                    return $attempt = $this->attemptTextStep($provider, $step, $previous, $allMessages, $continuationToken, $context);
                })->response();
            } catch (Throwable $exception) {
                $this->stepFailed($context, $attempt, $exception);

                throw $exception;
            }

            $prepared = $attempt->step ?? $pending;

            // Recorded before the tools run so a step that dies partway is still kept as far as it got.
            $steps = $steps->appendItem($completedStep = $this->buildStep($lastResult));

            $context?->recordStep($completedStep);

            $this->stepCompleted($context, $attempt, $lastResult);

            $accumulatedUsage = $accumulatedUsage->add($lastResult->usage);

            [$toolResults, $pendingApprovals] = $this->stepToolResultsWithOptions($lastResult, $prepared->isFinalStep, $prepared->tools, $prepared->options, $context);

            $completedStep->toolResults = $toolResults;

            $assistantMessage = $this->buildAssistantMessage($lastResult);
            $allMessages[] = $assistantMessage;
            $newMessages[] = $assistantMessage;

            if (Value::filled($toolResults)) {
                $toolResultMessage = new ToolResultMessage(collection($toolResults));
                $allMessages[] = $toolResultMessage;
                $newMessages[] = $toolResultMessage;
            }

            if (!$pendingApprovals->isEmpty()) {
                return $this->buildFinalResponse($steps, $newMessages, $lastResult)
                    ->withPendingApprovals($pendingApprovals);
            }

            if (Value::blank($toolResults) && $lastResult->finishReason !== FinishReason::Continue) {
                break;
            }

            $continuationToken = $lastResult->continuationToken;
            $previous = $prepared;
        }

        return $this->buildFinalResponse($steps, $newMessages, $lastResult);
    }

    /**
     * Stream text through a multi-step conversation loop.
     *
     * @param string $invocationId Unique invocation identifier
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider instance
     * @param string $model Model identifier
     * @param string|null $instructions System instructions
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Initial messages
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param array<string, mixed>|null $schema Response schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param int|null $timeout Timeout in seconds
     * @param array<string, \Crustum\Ai\Approvals\Decision>|null $approval Approval decisions keyed by tool call id
     * @param \Closure|null $recordApprovalResults Callback invoked with resolved approval tool results
     * @param array{0: \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall>, 1: array<string, \Crustum\Ai\Contracts\Tool|null>}|null $validatedApproval Pre-validated approval, reused instead of re-validating
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent, mixed, \Crustum\Ai\Gateway\StepResponse|null>
     */
    public function stream(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?array $approval = null,
        ?Closure $recordApprovalResults = null,
        ?array $validatedApproval = null,
        ?RunContext $context = null,
    ): Generator {
        $this->ensureSingleToolSearch($tools);

        $tools = $this->toolsSupportedBy($provider, $tools);

        $middleware = $this->middlewareFor($options);
        $steps = collection([]);
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $previous = null;
        $accumulatedUsage = new TextUsage();
        $finalReason = null;

        if ($approval !== null) {
            $resumption = $this->resumeFromApproval($approval, $messages, $tools, $validatedApproval, $context);

            $allMessages = $resumption->messages;

            if ($recordApprovalResults instanceof Closure) {
                $recordApprovalResults($resumption->results);
            }

            foreach ($resumption->results as $toolResult) {
                yield (new ToolResultEvent(
                    $this->generateEventId(),
                    $toolResult,
                    $toolResult->successful(),
                    $toolResult->error(),
                    time(),
                    denied: $toolResult->denied,
                ))->withInvocationId($invocationId);
            }

            if (!$resumption->shouldContinue) {
                yield (new StreamEnd(
                    $this->generateEventId(),
                    FinishReason::Stop->value,
                    $accumulatedUsage,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }
        } else {
            $allMessages = $this->settleAbandonedToolCalls($messages);
        }

        for ($step = 0; $step < $maxSteps; $step++) {
            $pending = new PendingStep(
                number: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                provider: $provider->name(),
                model: $model,
                instructions: $instructions,
                messages: $allMessages,
                tools: $tools,
                schema: $schema,
                options: $options?->forStep($step),
                steps: $steps->toList(),
                usage: $accumulatedUsage,
                timeout: $timeout,
                invocationId: $context?->invocationId,
            );

            // Held by reference because a short-circuiting middleware returns a result that is not the attempt.
            $attempt = null;
            $lastError = null;

            try {
                $stepResult = $this->runStep($pending, $middleware, function (PendingStep $step) use ($invocationId, $provider, $previous, $context, $allMessages, $continuationToken, &$attempt): StepResult {

                    $attempt = $this->attemptStreamStep($invocationId, $provider, $step, $previous, $allMessages, $continuationToken, $context);

                    return $attempt;
                });

                $reasoningDeltas = [];

                foreach ($stepResult as $event) {
                    yield $event;

                    if ($event instanceof Error) {
                        $lastError = $event;
                    }

                    if ($event instanceof ReasoningDelta) {
                        $reasoningDeltas[] = $event;
                    }
                }

                $prepared = $stepResult->step ?? $pending;
                $result = $stepResult->response();

                if (!$stepResult->streamed() && $result instanceof StepResponse) {
                    yield from $this->eventsFor($invocationId, $provider, $prepared->model, $result);
                }

                if ($result instanceof StepResponse && $result->reasoning === '') {
                    $result->reasoning = ReasoningDelta::combine($reasoningDeltas);
                }
            } catch (Throwable $exception) {
                $this->stepFailed($context, $attempt, $exception);

                throw $exception;
            }

            if (!$result instanceof StepResponse) {
                $exception = new StreamErrorException($lastError);

                $this->stepFailed($context, $attempt, $exception);

                throw $exception;
            }

            $this->stepCompleted($context, $attempt, $result);

            // Recorded before the tools run so a step that dies partway is still kept as far as it got.
            $steps = $steps->appendItem($completedStep = $this->buildStep($result));

            $context?->recordStep($completedStep);

            $accumulatedUsage = $accumulatedUsage->add($result->usage);
            $finalReason = $result->finishReason;

            $toolStream = $this->streamedStepToolResults(
                $result,
                $prepared->isFinalStep,
                $prepared->tools,
                $invocationId,
                $prepared->options,
                $context,
            );

            // Re-yielded rather than delegated so every event keeps a distinct key and iterator_to_array() drops none of them.
            foreach ($toolStream as $event) {
                yield $event;
            }

            [$toolResults, $pendingApprovals] = $toolStream->getReturn();

            $completedStep->toolResults = $toolResults;

            foreach ($toolResults as $toolResult) {
                yield (new ToolResultEvent(
                    $this->generateEventId(),
                    $toolResult,
                    $toolResult->successful(),
                    $toolResult->error(),
                    time(),
                ))->withInvocationId($invocationId);
            }

            $allMessages[] = $this->buildAssistantMessage($result);

            if (Value::filled($toolResults)) {
                $allMessages[] = new ToolResultMessage(collection($toolResults));
            }

            if (!$pendingApprovals->isEmpty()) {
                yield (new ToolApprovalRequest(
                    $this->generateEventId(),
                    $pendingApprovals,
                    time(),
                    $steps,
                ))->withInvocationId($invocationId);

                break;
            }

            if (Value::blank($toolResults) && $result->finishReason !== FinishReason::Continue) {
                break;
            }

            $continuationToken = $result->continuationToken;
            $previous = $prepared;
        }

        yield (new StreamEnd(
            $this->generateEventId(),
            ($finalReason ?? FinishReason::Stop)->value,
            $accumulatedUsage,
            time(),
            $steps,
        ))->withInvocationId($invocationId);

        return null;
    }

    /**
     * The middleware wrapping each step, as declared by the agent being run.
     *
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @return array<int, mixed>
     */
    protected function middlewareFor(?TextGenerationOptions $options): array
    {
        return $options?->agent instanceof HasMiddleware ? $options->agent->middleware() : [];
    }

    /**
     * Run the given step through the middleware stack.
     *
     * Each middleware receives a StepResult from $next, whether the inner layer streamed, short-circuited or replaced the step.
     *
     * @param \Crustum\Ai\PendingStep $step Pending step
     * @param array<int, mixed> $middleware Middleware stack
     * @param \Closure(\Crustum\Ai\PendingStep): \Crustum\Ai\Gateway\StepResult $run Innermost step runner
     */
    protected function runStep(PendingStep $step, array $middleware, Closure $run): StepResult
    {
        $next = $run;

        foreach (array_reverse($middleware) as $pipe) {
            $next = fn(PendingStep $step): StepResult => $this->toStepResult(
                $pipe instanceof Closure ? $pipe($step, $next) : (is_string($pipe) ? new $pipe() : $pipe)->handle($step, $next),
            );
        }

        return $next($step);
    }

    /**
     * Normalize a middleware result to a step result.
     *
     * @param mixed $result Middleware result
     */
    protected function toStepResult(mixed $result): StepResult
    {
        return match (true) {
            $result instanceof StepResult => $result,
            $result instanceof StepResponse => new StepResult($result),
            default => throw new LogicException('Agent middleware must return the next step result or a StepResponse.'),
        };
    }

    /**
     * Send the prepared step to the model for a synchronous response, reporting its start and any failure.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider instance
     * @param \Crustum\Ai\PendingStep $step Prepared step
     * @param \Crustum\Ai\PendingStep|null $previous Previously attempted step
     * @param array<int, \Crustum\Ai\Messages\Message> $history Run history
     * @param string|null $continuationToken Provider continuation token
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     */
    protected function attemptTextStep(TextProvider $provider, PendingStep $step, ?PendingStep $previous, array $history, ?string $continuationToken, ?RunContext $context): StepResult
    {
        $stepContext = $this->stepContextFor($step, $previous, $history, $continuationToken);

        $context?->startingStep($stepContext, $step->messages, $step->options, $step->model);

        $startedAt = hrtime(true);

        try {
            $source = $this->gateway->generateTextStep(
                $provider,
                $step->model,
                $step->instructions,
                $step->messages,
                $step->tools,
                $step->schema,
                $step->options,
                $step->timeout,
                $stepContext,
            );
        } catch (Throwable $throwable) {
            $context?->stepFailed($stepContext, $throwable, $this->elapsedMilliseconds($startedAt), $step->model);

            throw $throwable;
        }

        return new StepResult($source, $step, $stepContext, $startedAt);
    }

    /**
     * Send the prepared step to the model for a streamed response, reporting its start and any failure.
     *
     * @param string $invocationId Unique invocation identifier
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider instance
     * @param \Crustum\Ai\PendingStep $step Prepared step
     * @param \Crustum\Ai\PendingStep|null $previous Previously attempted step
     * @param array<int, \Crustum\Ai\Messages\Message> $history Run history
     * @param string|null $continuationToken Provider continuation token
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     */
    protected function attemptStreamStep(string $invocationId, TextProvider $provider, PendingStep $step, ?PendingStep $previous, array $history, ?string $continuationToken, ?RunContext $context): StepResult
    {
        $stepContext = $this->stepContextFor($step, $previous, $history, $continuationToken);

        $context?->startingStep($stepContext, $step->messages, $step->options, $step->model);

        $startedAt = hrtime(true);

        try {
            $source = $this->gateway->generateStreamStep(
                $invocationId,
                $provider,
                $step->model,
                $step->instructions,
                $step->messages,
                $step->tools,
                $step->schema,
                $step->options,
                $step->timeout,
                $stepContext,
            );
        } catch (Throwable $throwable) {
            $context?->stepFailed($stepContext, $throwable, $this->elapsedMilliseconds($startedAt), $step->model);

            throw $throwable;
        }

        return new StepResult($source, $step, $stepContext, $startedAt);
    }

    /**
     * Report a completed generation attempt, unless middleware answered the step itself.
     *
     * A step that middleware answered itself was never attempted and reports nothing.
     *
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @param \Crustum\Ai\Gateway\StepResult|null $attempt Attempted step result
     * @param \Crustum\Ai\Gateway\StepResponse $response Step response
     */
    protected function stepCompleted(?RunContext $context, ?StepResult $attempt, StepResponse $response): void
    {
        if ($attempt instanceof StepResult) {
            $context?->stepCompleted($attempt->context, $response, $this->elapsedMilliseconds($attempt->startedAt), $attempt->step->model);
        }
    }

    /**
     * Report a failed generation attempt when one was made.
     *
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @param \Crustum\Ai\Gateway\StepResult|null $attempt Attempted step result
     * @param \Throwable $exception The failure
     */
    protected function stepFailed(?RunContext $context, ?StepResult $attempt, Throwable $exception): void
    {
        if ($attempt instanceof StepResult) {
            $context?->stepFailed($attempt->context, $exception, $this->elapsedMilliseconds($attempt->startedAt), $attempt->step->model);
        }
    }

    /**
     * Build the context for a step; a continuation replays only unchanged history, model and instructions.
     *
     * @param \Crustum\Ai\PendingStep $step Prepared step
     * @param \Crustum\Ai\PendingStep|null $previous Previously attempted step
     * @param array<int, \Crustum\Ai\Messages\Message> $history Run history
     * @param string|null $continuationToken Provider continuation token
     */
    protected function stepContextFor(PendingStep $step, ?PendingStep $previous, array $history, ?string $continuationToken): StepContext
    {
        $unchanged = $previous instanceof PendingStep
            && $step->messages === $history
            && $step->model === $previous->model
            && $step->instructions === $previous->instructions;

        return new StepContext(
            stepNumber: $step->number,
            isFinalStep: $step->isFinalStep,
            continuationToken: $unchanged ? $continuationToken : null,
        );
    }

    /**
     * The stream events describing a step response that was produced without streaming.
     *
     * @param string $invocationId Unique invocation identifier
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider instance
     * @param string $model Model identifier
     * @param \Crustum\Ai\Gateway\StepResponse $response Step response
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    protected function eventsFor(string $invocationId, TextProvider $provider, string $model, StepResponse $response): Generator
    {
        yield (new StreamStart($this->generateEventId(), $provider->name(), $model, time()))->withInvocationId($invocationId);

        if (Value::filled($response->text)) {
            $messageId = $this->generateEventId();

            yield (new TextStart($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
            yield (new TextDelta($this->generateEventId(), $messageId, $response->text, time()))->withInvocationId($invocationId);
            yield (new TextEnd($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
        }

        foreach ($response->toolCalls as $toolCall) {
            yield (new ToolCallEvent($this->generateEventId(), $toolCall, time()))->withInvocationId($invocationId);
        }
    }

    /**
     * Resolve the maximum number of steps for the generation loop.
     *
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @return int
     */
    protected function resolveMaxSteps(?TextGenerationOptions $options, array $tools): int
    {
        if ($options?->maxSteps !== null) {
            return max(1, $options->maxSteps);
        }

        $count = ToolSearch::budget($tools);
        $maxSteps = $count > 0 ? min((int)round($count * 1.5), self::DEFAULT_MAX_STEPS) : 5;

        return $maxSteps + (int)RepairToolCalls::isAppliedTo($options?->agent);
    }

    /**
     * Resolve tool results using the generation options for the current step.
     *
     * @param \Crustum\Ai\Gateway\StepResponse $result Step response
     * @param bool $isFinalStep Whether this is the final step
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return array{0: array<int, \Crustum\Ai\Responses\Data\ToolResult>, 1: \Cake\Collection\Collection<array-key, \Crustum\Ai\Approvals\PendingApproval>}
     */
    private function stepToolResultsWithOptions(StepResponse $result, bool $isFinalStep, array $tools, ?TextGenerationOptions $options, ?RunContext $context = null): array
    {
        return $this->withRepairSetting(
            $options,
            fn(): array => $this->stepToolResults($result, $isFinalStep, $tools, $context),
        );
    }

    /**
     * Run the given callback with the tool call repair setting the step's agent asks for.
     *
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param \Closure(): mixed $callback Callback
     */
    private function withRepairSetting(?TextGenerationOptions $options, Closure $callback): mixed
    {
        $repairsToolCalls = $this->repairsToolCalls;

        $this->repairsToolCalls = RepairToolCalls::isAppliedTo($options?->agent);

        try {
            return $callback();
        } finally {
            $this->repairsToolCalls = $repairsToolCalls;
        }
    }

    /**
     * Determine the tool results to continue the loop with, plus any pending approvals that pause it.
     *
     * @param \Crustum\Ai\Gateway\StepResponse $result Step response
     * @param bool $isFinalStep Whether this is the final step
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return array{0: array<int, \Crustum\Ai\Responses\Data\ToolResult>, 1: \Cake\Collection\Collection<array-key, \Crustum\Ai\Approvals\PendingApproval>}
     */
    protected function stepToolResults(StepResponse $result, bool $isFinalStep, array $tools, ?RunContext $context = null): array
    {
        return $this->earlyStepToolResults($result)
            ?? $this->approvalAwareToolResults($result->toolCalls, $tools, $isFinalStep, $context);
    }

    /**
     * The early outcome for steps that pause or execute nothing, or null when tools should run.
     *
     * @param \Crustum\Ai\Gateway\StepResponse $result Step response
     * @return array{0: array<int, \Crustum\Ai\Responses\Data\ToolResult>, 1: \Cake\Collection\Collection<array-key, \Crustum\Ai\Approvals\PendingApproval>}|null
     */
    protected function earlyStepToolResults(StepResponse $result): ?array
    {
        if (Value::filled($result->pendingApprovals)) {
            return [[], collection($result->pendingApprovals)];
        }

        if ($result->finishReason !== FinishReason::ToolCalls || Value::blank($result->toolCalls)) {
            return [[], collection([])];
        }

        return null;
    }

    /**
     * Get tool results while streaming sub-agent activity.
     *
     * @param \Crustum\Ai\Gateway\StepResponse $result Step response
     * @param bool $isFinalStep Whether this is the final step
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param string $invocationId Unique invocation identifier
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\ToolResult, mixed, array{0: array<int, \Crustum\Ai\Responses\Data\ToolResult>, 1: \Cake\Collection\Collection<array-key, \Crustum\Ai\Approvals\PendingApproval>}>
     */
    protected function streamedStepToolResults(StepResponse $result, bool $isFinalStep, array $tools, string $invocationId, ?TextGenerationOptions $options = null, ?RunContext $context = null): Generator
    {
        $earlyOutcome = $this->earlyStepToolResults($result);

        if ($earlyOutcome !== null) {
            return $earlyOutcome;
        }

        [$resolved, $pendingApprovals] = $this->withRepairSetting(
            $options,
            fn(): array => $this->resolveToolCalls($result->toolCalls, $tools, $isFinalStep),
        );

        $toolResults = [];

        foreach ($resolved as [$toolCall, $tool]) {
            if (!$tool instanceof AgentTool || $isFinalStep) {
                $toolResults[] = $this->withRepairSetting(
                    $options,
                    fn(): ToolResult => $this->resolvedToolResult($toolCall, $tool, $isFinalStep, $tools, $context),
                );

                continue;
            }

            $events = $this->executeAgentToolStreaming($tool, $toolCall->arguments, $toolCall->id, $context);

            yield from $this->preliminaryToolResults($events, $toolCall, $invocationId);

            $toolResults[] = $this->recordedToolResult($this->toolResult($toolCall, $events->getReturn()), $context);
        }

        return [$toolResults, $pendingApprovals];
    }

    /**
     * Report the output a still running tool has produced so far.
     *
     * @param \Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent, mixed, string> $events Sub-agent events
     * @param \Crustum\Ai\Responses\Data\ToolCall $toolCall Tool call
     * @param string $invocationId Unique invocation identifier
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\ToolResult>
     */
    protected function preliminaryToolResults(Generator $events, ToolCall $toolCall, string $invocationId): Generator
    {
        $deltas = [];
        $written = 0;
        $reportedAt = 0;

        foreach ($events as $event) {
            if ($event instanceof TextDelta) {
                $deltas[] = $event;
                $written += strlen($event->delta);

                // Each report restates the whole output, so one per delta would grow the stream quadratically.
                if ($written - $reportedAt < self::PRELIMINARY_OUTPUT_BYTES) {
                    continue;
                }
            }

            $reportedAt = $written;

            $result = $this->toolResult($toolCall, TextDelta::combine($deltas));

            yield (new ToolResultEvent(
                $this->generateEventId(),
                $result,
                $result->successful(),
                $result->error(),
                time(),
                preliminary: true,
            ))->withInvocationId($invocationId);
        }
    }

    /**
     * Execute a sub-agent tool, streaming its activity while reporting through the run context.
     *
     * @param \Crustum\Ai\Tools\AgentTool $tool Sub-agent tool
     * @param array<string, mixed> $arguments Tool arguments
     * @param string|null $toolCallId Stable provider tool-call ID
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent, mixed, string>
     */
    protected function executeAgentToolStreaming(AgentTool $tool, array $arguments, ?string $toolCallId = null, ?RunContext $context = null): Generator
    {
        $toolInvocationId = strtolower(Text::uuid());
        $parentInvocationId = $context?->invocationId;

        $context?->invokingTool($tool, $arguments, $toolInvocationId);

        $startedAt = hrtime(true);

        $events = $tool->stream(new Request($arguments, $toolCallId, $toolInvocationId));

        // Advanced by hand so every resumption of the child run, not only the first, sees this tool call as its parent.
        while (ParentInvocation::within($parentInvocationId, $toolInvocationId, fn(): bool => $events->valid())) {
            yield $events->current();

            ParentInvocation::within($parentInvocationId, $toolInvocationId, function () use ($events): void {
                $events->next();
            });
        }

        $result = $events->getReturn();

        $context?->toolInvoked($tool, $arguments, $result, $toolInvocationId, $this->elapsedMilliseconds($startedAt));

        return $result;
    }

    /**
     * Resolve tool results while pausing any tool calls that require human approval.
     *
     * @param array<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls Tool calls from the response
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param bool $isFinalStep Whether this is the final step
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return array{0: array<int, \Crustum\Ai\Responses\Data\ToolResult>, 1: \Cake\Collection\Collection<array-key, \Crustum\Ai\Approvals\PendingApproval>}
     */
    protected function approvalAwareToolResults(array $toolCalls, array $tools, bool $isFinalStep = false, ?RunContext $context = null): array
    {
        [$resolved, $pendingApprovals] = $this->resolveToolCalls($toolCalls, $tools, $isFinalStep);

        $toolResults = array_map(
            fn(array $pair): ToolResult => $this->resolvedToolResult($pair[0], $pair[1], $isFinalStep, $tools, $context),
            $resolved,
        );

        return [$toolResults, $pendingApprovals];
    }

    /**
     * Execute the resolved tool call, or mark it as repaired or exhausted when it can no longer run.
     *
     * @param \Crustum\Ai\Responses\Data\ToolCall $toolCall Tool call
     * @param \Crustum\Ai\Contracts\Tool|null $tool Resolved tool
     * @param bool $isFinalStep Whether this is the final step
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     */
    protected function resolvedToolResult(ToolCall $toolCall, ?Tool $tool, bool $isFinalStep, array $tools = [], ?RunContext $context = null): ToolResult
    {
        return $this->recordedToolResult($this->toolResult(
            $toolCall,
            match (true) {
                !$tool instanceof Tool && $this->repairsToolCalls => "Tool '{$toolCall->name}' does not exist. Available tools: {$this->availableToolNames($tools)}.",
                $isFinalStep => 'The agent reached its maximum number of steps without running this tool call.',
                default => $this->executeTool($tool, $toolCall->arguments, $toolCall->id, $context),
            },
            failed: !$tool instanceof Tool || $isFinalStep,
        ), $context);
    }

    /**
     * Answer the recorded step with the result its tool just produced.
     *
     * @param \Crustum\Ai\Responses\Data\ToolResult $result Tool result
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     */
    protected function recordedToolResult(ToolResult $result, ?RunContext $context): ToolResult
    {
        $context?->recordToolResult($result);

        return $result;
    }

    /**
     * Create a tool result for the given tool call.
     *
     * @param \Crustum\Ai\Responses\Data\ToolCall $toolCall Tool call
     * @param mixed $result Tool result
     * @param bool $failed Whether the tool call failed
     */
    protected function toolResult(ToolCall $toolCall, mixed $result, bool $failed = false): ToolResult
    {
        return new ToolResult(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            $result,
            $toolCall->resultId,
            failed: $failed,
        );
    }

    /**
     * Split the step's tool calls into executable pairs and pending approvals.
     *
     * @param array<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls Tool calls from the response
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param bool $isFinalStep Whether this is the final step
     * @return array{0: array<int, array{0: \Crustum\Ai\Responses\Data\ToolCall, 1: \Crustum\Ai\Contracts\Tool|null}>, 1: \Cake\Collection\Collection<array-key, \Crustum\Ai\Approvals\PendingApproval>}
     */
    protected function resolveToolCalls(array $toolCalls, array $tools, bool $isFinalStep): array
    {
        $pendingApprovals = [];
        $resolved = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            $approval = $tool instanceof Tool ? $this->approvalForTool($tool, $toolCall) : null;

            if ($approval instanceof Approval) {
                $pendingApprovals[] = new PendingApproval(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $approval->reason,
                );

                continue;
            }

            if (!$tool instanceof Tool && !$isFinalStep && !$this->repairsToolCalls) {
                throw new NoSuchToolException($toolCall->name);
            }

            $resolved[] = [$toolCall, $tool];
        }

        return [$resolved, collection($pendingApprovals)];
    }

    /**
     * The names of the locally executable tools, as advertised back to a model that called an unknown one.
     *
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @return string
     */
    protected function availableToolNames(array $tools): string
    {
        $names = array_map(
            ToolNameResolver::resolve(...),
            array_filter($tools, fn(mixed $tool): bool => $tool instanceof Tool),
        );

        return $names !== [] ? implode(', ', $names) : 'none';
    }

    /**
     * Ensure at most one tool search wrapper is registered per request.
     *
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     */
    protected function ensureSingleToolSearch(array $tools): void
    {
        if (count(array_filter($tools, fn($tool): bool => $tool instanceof ToolSearch)) > 1) {
            throw new LogicException('Only a single tool search wrapper may be registered per request.');
        }
    }

    /**
     * Drop provider tools the provider cannot run, unwrapping tool search into its deferred tools.
     *
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @return array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool>
     */
    protected function toolsSupportedBy(TextProvider $provider, array $tools): array
    {
        /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $supported */
        $supported = collection($tools)->unfold(
            fn(mixed $tool): array => match (true) {
                $tool instanceof CodeExecution => $provider instanceof SupportsCodeExecution ? [$tool] : [],
                $tool instanceof FileSearch => $provider instanceof SupportsFileSearch ? [$tool] : [],
                $tool instanceof ToolSearch => $provider instanceof SupportsToolSearch ? [$tool] : $tool->tools,
                $tool instanceof WebFetch => $provider instanceof SupportsWebFetch ? [$tool] : [],
                $tool instanceof WebSearch => $provider instanceof SupportsWebSearch ? [$tool] : [],
                $tool instanceof ProviderTool => [],
                default => [$tool],
            },
        );

        return $supported->toList();
    }

    /**
     * Apply the approval's decisions to the pending pause, returning the updated history and resume state.
     *
     * @param array<string, \Crustum\Ai\Approvals\Decision> $approval Approval decisions keyed by tool call id
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param array{0: \Cake\Collection\Collection<array-key, \Crustum\Ai\Responses\Data\ToolCall>, 1: array<string, \Crustum\Ai\Contracts\Tool|null>}|null $validatedApproval Pre-validated approval, reused instead of re-validating
     * @return \Crustum\Ai\Gateway\ApprovalResumption
     */
    protected function resumeFromApproval(array $approval, array $messages, array $tools, ?array $validatedApproval = null, ?RunContext $context = null): ApprovalResumption
    {
        $messages = $this->settleAbandonedToolCalls($messages, exceptLatestAssistantTurn: true);

        [$approvalResults, $shouldContinue] = $this->resolveApprovalResults($approval, $messages, $tools, $validatedApproval, $context);

        $newMessages = [];

        if (Value::filled($approvalResults)) {
            [$messages, $newMessages] = $this->appendApprovalResults($messages, $approvalResults);
        }

        return new ApprovalResumption(
            messages: $messages,
            newMessages: $newMessages,
            results: $approvalResults,
            shouldContinue: $shouldContinue,
        );
    }

    /**
     * Resolve the approval decisions into tool results.
     *
     * @param array<string, \Crustum\Ai\Approvals\Decision> $approval Approval decisions keyed by tool call id
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @param array{0: \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall>, 1: array<string, \Crustum\Ai\Contracts\Tool|null>}|null $validatedApproval Pre-validated approval, reused instead of re-validating
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return array{0: array<int, \Crustum\Ai\Responses\Data\ToolResult>, 1: bool}
     */
    protected function resolveApprovalResults(array $approval, array $messages, array $tools, ?array $validatedApproval = null, ?RunContext $context = null): array
    {
        [$pendingToolCalls, $resolvedTools] = $validatedApproval ?? $this->validateApproval($approval, $messages, $tools);

        $toolResults = [];
        $hasBareRejection = false;

        foreach ($pendingToolCalls as $toolCall) {
            $tool = $resolvedTools[$toolCall->id];

            $decision = $tool instanceof Tool && !$tool instanceof Approvable
                ? Decision::reject('This tool call was not executed because it was not pending approval.')
                : $approval[$toolCall->id] ?? $approval['*'] ?? Decision::reject('The user rejected this tool call.');

            if ($decision->isRejected()) {
                $hasBareRejection = $hasBareRejection || $decision->result === null;

                $toolResults[] = new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $decision->result ?? 'The user rejected this tool call.',
                    $toolCall->resultId,
                    denied: true,
                );

                continue;
            }

            $arguments = $decision->arguments ?? $toolCall->arguments;

            if (!$tool instanceof Tool) {
                throw new NoSuchToolException($toolCall->name);
            }

            $failed = false;

            try {
                $result = $this->executeTool($tool, $arguments, $toolCall->id, $context);
            } catch (Throwable $exception) {
                $failed = true;
                $result = 'The tool call failed: ' . $exception->getMessage();
            }

            $toolResults[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $arguments,
                $result,
                $toolCall->resultId,
                failed: $failed,
            );
        }

        return [$toolResults, !$hasBareRejection];
    }

    /**
     * Validate the approval's decisions against the pending tool calls, throwing on any mismatch.
     *
     * @param array<string, \Crustum\Ai\Approvals\Decision> $approval Approval decisions keyed by tool call id
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> $tools Available tools
     * @return array{0: \Cake\Collection\Collection<array-key, \Crustum\Ai\Responses\Data\ToolCall>, 1: array<string, \Crustum\Ai\Contracts\Tool|null>}
     * @throws \Crustum\Ai\Approvals\ApprovalMismatchException
     */
    public function validateApproval(array $approval, array $messages, array $tools): array
    {
        [$pendingToolCalls, $resolvedToolCallIds] = $this->pendingToolCalls($messages);

        $pending = array_values(iterator_to_array($pendingToolCalls));

        $pendingIds = array_map(fn(ToolCall $toolCall): string => $toolCall->id, $pending);

        $decisionIds = array_values(array_filter(
            array_keys($approval),
            fn($id): bool => $id !== '*',
        ));

        $resolvedTools = [];
        $approvals = [];

        foreach ($pending as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);
            $resolvedTools[$toolCall->id] = $tool;
            $approvals[$toolCall->id] = $this->approvalForTool($tool, $toolCall);
        }

        $gated = array_values(array_filter(
            $pending,
            fn(ToolCall $toolCall): bool => $approvals[$toolCall->id] instanceof Approval,
        ));

        $unknown = array_values(array_diff($decisionIds, $pendingIds));

        $gatedIds = array_map(fn(ToolCall $toolCall): string => $toolCall->id, $gated);

        $missing = array_key_exists('*', $approval)
            ? []
            : array_values(array_diff($gatedIds, $decisionIds));

        if ($unknown !== [] || $missing !== []) {
            $message = array_intersect($unknown, $resolvedToolCallIds) !== []
                ? 'Approval decisions include already-resolved tool call ids.'
                : 'Approval decisions do not match the pending tool calls.';

            throw new ApprovalMismatchException($message, $this->pendingApprovalsFor(collection($gated), $approvals));
        }

        if ($pending === []) {
            throw new ApprovalMismatchException('There are no tool calls pending approval.', collection([]));
        }

        return [collection($pending), $resolvedTools];
    }

    /**
     * Resolve the approval requirement for a tool call, or null when it is not gated.
     *
     * @param \Crustum\Ai\Contracts\Tool|null $tool Tool instance
     * @param \Crustum\Ai\Responses\Data\ToolCall $toolCall Tool call
     * @return \Crustum\Ai\Approvals\Approval|null
     */
    protected function approvalForTool(?Tool $tool, ToolCall $toolCall): ?Approval
    {
        return $tool instanceof Approvable
            ? $tool->shouldRequestApproval(new Request($toolCall->arguments, $toolCall->id))
            : null;
    }

    /**
     * Generate a unique stream event identifier.
     *
     * @return string
     */
    protected function generateEventId(): string
    {
        return strtolower(Text::uuid());
    }

    /**
     * Build an assistant message from the given step response.
     *
     * @param \Crustum\Ai\Gateway\StepResponse $result Step response
     * @return \Crustum\Ai\Messages\AssistantMessage
     */
    protected function buildAssistantMessage(StepResponse $result): AssistantMessage
    {
        return new AssistantMessage(
            $result->text,
            collection($result->toolCalls),
            $result->replayBlocks,
        );
    }

    /**
     * Build a step from a response; tool results start empty and are answered as their tools run.
     *
     * @param \Crustum\Ai\Gateway\StepResponse $result Step response
     * @return \Crustum\Ai\Responses\Data\Step
     */
    protected function buildStep(StepResponse $result): Step
    {
        return (new Step(
            $result->text,
            $result->toolCalls,
            [],
            $result->finishReason,
            $result->usage,
            $result->meta,
            $result->reasoning,
            $result->replayBlocks,
            $result->providerToolCalls,
        ))->withRawResponse($result->raw);
    }

    /**
     * Build the final text response from all generated steps.
     *
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\Step> $steps Generated steps
     * @param array<int, \Crustum\Ai\Messages\Message> $newMessages Messages appended during the loop
     * @param \Crustum\Ai\Gateway\StepResponse|null $lastResult Last step response
     * @return \Crustum\Ai\Responses\TextResponse
     */
    protected function buildFinalResponse(
        CollectionInterface $steps,
        array $newMessages,
        ?StepResponse $lastResult,
    ): TextResponse {
        /** @var \Crustum\Ai\Responses\Data\Step|null $finalStep */
        $finalStep = $steps->last();

        if ($finalStep === null) {
            throw new RuntimeException('No steps generated');
        }

        /** @var \Cake\Collection\CollectionInterface<int, string> $reasonings */
        $reasonings = $steps->map(fn(Step $step): string => $step->reasoning);

        $reasoningText = static::joinReasoning($reasonings->toList());

        $totalUsage = $steps->reduce(
            fn(TextUsage $carry, Step $step): TextUsage => $carry->add($step->usage),
            new TextUsage(),
        );

        $messages = collection($newMessages);

        $steps->rewind();
        $steps = collection($steps->toList());

        if ($lastResult?->structured !== null) {
            return (new StructuredTextResponse(
                $lastResult->structured,
                $finalStep->text,
                $totalUsage,
                $finalStep->meta,
            ))->withMessages($messages)->withToolCallsAndResults(
                toolCalls: $this->flattenStepToolCalls($steps),
                toolResults: $this->flattenStepToolResults($steps),
            )->withSteps($steps)->withReasoning($reasoningText)->withRawResponse($lastResult->raw);
        }

        return (new TextResponse(
            $finalStep->text,
            $totalUsage,
            $finalStep->meta,
        ))->withMessages($messages)->withSteps($steps)->withReasoning($reasoningText)->withRawResponse($lastResult?->raw);
    }

    /**
     * Flatten tool calls from all steps into a single collection.
     *
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\Step> $steps Generated steps
     * @return \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall>
     */
    protected function flattenStepToolCalls(CollectionInterface $steps): CollectionInterface
    {
        return $steps->reduce(
            fn(Collection $carry, Step $step): CollectionInterface => $carry->append($step->toolCalls),
            collection([]),
        );
    }

    /**
     * Flatten tool results from all steps into a single collection.
     *
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\Step> $steps Generated steps
     * @return \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolResult>
     */
    protected function flattenStepToolResults(CollectionInterface $steps): CollectionInterface
    {
        return $steps->reduce(
            fn(Collection $carry, Step $step): CollectionInterface => $carry->append($step->toolResults),
            collection([]),
        );
    }
}
