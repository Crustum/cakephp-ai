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
use Crustum\Ai\Contracts\Providers\SupportsToolSearch;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Exception\NoSuchToolException;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Gateway\Trait\HandlesToolApprovalsTrait;
use Crustum\Ai\Gateway\Trait\InvokesToolsTrait;
use Crustum\Ai\Gateway\Trait\MeasuresDurationTrait;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Step;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\StructuredTextResponse;
use Crustum\Ai\Responses\TextResponse;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Streaming\Event\ToolResult as ToolResultEvent;
use Crustum\Ai\Tools\Request;
use Crustum\Ai\Tools\ToolNameResolver;
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
    use MeasuresDurationTrait;

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
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
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
        $this->ensureToolSearchIsApplicable($provider, $tools);

        $steps = collection([]);
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $lastResult = null;

        if ($approval !== null) {
            $resumption = $this->resumeFromApproval($approval, $messages, $tools, null, $context);

            $allMessages = $resumption->messages;
            $newMessages = $resumption->newMessages;

            if ($recordApprovalResults instanceof Closure) {
                $recordApprovalResults($resumption->results);
            }

            if (!$resumption->shouldContinue) {
                return (new TextResponse('', new Usage(), new Meta($provider->name(), $model)))
                    ->withMessages(collection($newMessages));
            }
        } else {
            $allMessages = $this->settleAbandonedToolCalls($messages);
            $newMessages = [];
        }

        for ($step = 0; $step < $maxSteps; $step++) {
            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

            $stepOptions = $options?->forStep($step);

            $context?->startingStep($stepContext, $allMessages, $stepOptions);

            $startedAt = hrtime(true);

            try {
                $lastResult = $this->gateway->generateTextStep(
                    $provider,
                    $model,
                    $instructions,
                    $allMessages,
                    $tools,
                    $schema,
                    $stepOptions,
                    $timeout,
                    $stepContext,
                );
            } catch (Throwable $exception) {
                $context?->stepFailed($stepContext, $exception, $this->elapsedMilliseconds($startedAt));

                throw $exception;
            }

            $context?->stepCompleted($stepContext, $lastResult, $this->elapsedMilliseconds($startedAt));

            [$toolResults, $pendingApprovals] = $this->stepToolResultsWithOptions($lastResult, $stepContext->isFinalStep, $tools, $options, $context);

            $steps = $steps->appendItem($this->buildStep($lastResult, $toolResults));

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
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
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
        $this->ensureToolSearchIsApplicable($provider, $tools);

        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $accumulatedUsage = new Usage();
        $finalReason = null;

        if ($approval !== null) {
            $resumption = $this->resumeFromApproval($approval, $messages, $tools, $validatedApproval, $context);

            $allMessages = $resumption->messages;

            if ($recordApprovalResults instanceof Closure) {
                $recordApprovalResults($resumption->results);
            }

            foreach ($resumption->results as $toolResult) {
                $failed = in_array($toolResult->id, $resumption->failedToolCallIds, true);

                yield (new ToolResultEvent(
                    $this->generateEventId(),
                    $toolResult,
                    !$toolResult->denied && !$failed,
                    $toolResult->denied || $failed ? $toolResult->result : null,
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
            $stepContext = new StepContext(
                stepNumber: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                continuationToken: $continuationToken,
            );

            $stepOptions = $options?->forStep($step);

            $context?->startingStep($stepContext, $allMessages, $stepOptions);

            $lastError = null;
            $startedAt = hrtime(true);

            try {
                $stream = $this->gateway->generateStreamStep(
                    $invocationId,
                    $provider,
                    $model,
                    $instructions,
                    $allMessages,
                    $tools,
                    $schema,
                    $stepOptions,
                    $timeout,
                    $stepContext,
                );

                foreach ($stream as $event) {
                    yield $event;

                    if ($event instanceof Error) {
                        $lastError = $event;
                    }
                }

                $result = $stream->getReturn();
            } catch (Throwable $exception) {
                $context?->stepFailed($stepContext, $exception, $this->elapsedMilliseconds($startedAt));

                throw $exception;
            }

            if (!$result instanceof StepResponse) {
                $exception = new StreamErrorException($lastError);

                $context?->stepFailed($stepContext, $exception, $this->elapsedMilliseconds($startedAt));

                throw $exception;
            }

            $context?->stepCompleted($stepContext, $result, $this->elapsedMilliseconds($startedAt));

            $accumulatedUsage = $accumulatedUsage->add($result->usage);
            $finalReason = $result->finishReason;

            [$toolResults, $pendingApprovals] = $this->stepToolResultsWithOptions($result, $stepContext->isFinalStep, $tools, $options, $context);

            foreach ($toolResults as $toolResult) {
                $successful = !$stepContext->isFinalStep && $this->findTool($toolResult->name, $tools) instanceof Tool;

                yield (new ToolResultEvent(
                    $this->generateEventId(),
                    $toolResult,
                    $successful,
                    $successful ? null : $toolResult->result,
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
                    $result->providerContentBlocks,
                ))->withInvocationId($invocationId);

                break;
            }

            if (Value::blank($toolResults) && $result->finishReason !== FinishReason::Continue) {
                break;
            }

            $continuationToken = $result->continuationToken;
        }

        yield (new StreamEnd(
            $this->generateEventId(),
            ($finalReason ?? FinishReason::Stop)->value,
            $accumulatedUsage,
            time(),
        ))->withInvocationId($invocationId);

        return null;
    }

    /**
     * Resolve the maximum number of steps for the generation loop.
     *
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
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
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return array{0: array<int, \Crustum\Ai\Responses\Data\ToolResult>, 1: \Cake\Collection\Collection<array-key, \Crustum\Ai\Approvals\PendingApproval>}
     */
    private function stepToolResultsWithOptions(StepResponse $result, bool $isFinalStep, array $tools, ?TextGenerationOptions $options, ?RunContext $context = null): array
    {
        $repairsToolCalls = $this->repairsToolCalls;

        $this->repairsToolCalls = RepairToolCalls::isAppliedTo($options?->agent);

        try {
            return $this->stepToolResults($result, $isFinalStep, $tools, $context);
        } finally {
            $this->repairsToolCalls = $repairsToolCalls;
        }
    }

    /**
     * Determine the tool results to continue the loop with, plus any pending approvals that pause it.
     *
     * @param \Crustum\Ai\Gateway\StepResponse $result Step response
     * @param bool $isFinalStep Whether this is the final step
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return array{0: array<int, \Crustum\Ai\Responses\Data\ToolResult>, 1: \Cake\Collection\Collection<array-key, \Crustum\Ai\Approvals\PendingApproval>}
     */
    protected function stepToolResults(StepResponse $result, bool $isFinalStep, array $tools, ?RunContext $context = null): array
    {
        if (Value::filled($result->pendingApprovals)) {
            return [[], collection($result->pendingApprovals)];
        }

        if ($result->finishReason !== FinishReason::ToolCalls || Value::blank($result->toolCalls)) {
            return [[], collection([])];
        }

        return $this->approvalAwareToolResults($result->toolCalls, $tools, $isFinalStep, $context);
    }

    /**
     * Resolve tool results while pausing any tool calls that require human approval.
     *
     * @param array<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls Tool calls from the response
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     * @param bool $isFinalStep Whether this is the final step
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return array{0: array<int, \Crustum\Ai\Responses\Data\ToolResult>, 1: \Cake\Collection\Collection<array-key, \Crustum\Ai\Approvals\PendingApproval>}
     */
    protected function approvalAwareToolResults(array $toolCalls, array $tools, bool $isFinalStep = false, ?RunContext $context = null): array
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

        $toolResults = array_map(function (array $pair) use ($tools, $isFinalStep, $context): ToolResult {
            [$toolCall, $tool] = $pair;

            return new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                match (true) {
                    !$tool instanceof Tool && $this->repairsToolCalls => "Tool '{$toolCall->name}' does not exist. Available tools: {$this->availableToolNames($tools)}.",
                    $isFinalStep => 'The agent reached its maximum number of steps without running this tool call.',
                    default => $this->executeTool($tool, $toolCall->arguments, $toolCall->id, $context),
                },
                $toolCall->resultId,
            );
        }, $resolved);

        return [$toolResults, collection($pendingApprovals)];
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
     * Ensure hosted tool search is only used with a supporting provider and a single wrapper.
     *
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     */
    protected function ensureToolSearchIsApplicable(TextProvider $provider, array $tools): void
    {
        $wrappers = array_filter($tools, fn($tool): bool => $tool instanceof ToolSearch);

        if ($wrappers === []) {
            return;
        }

        if (!$provider instanceof SupportsToolSearch) {
            throw new LogicException('Provider [' . $provider->name() . '] does not support tool search.');
        }

        if (count($wrappers) > 1) {
            throw new LogicException('Only a single tool search wrapper may be registered per request.');
        }
    }

    /**
     * Apply the approval's decisions to the pending pause, returning the updated history and resume state.
     *
     * @param array<string, \Crustum\Ai\Approvals\Decision> $approval Approval decisions keyed by tool call id
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     * @param array{0: \Cake\Collection\Collection<array-key, \Crustum\Ai\Responses\Data\ToolCall>, 1: array<string, \Crustum\Ai\Contracts\Tool|null>}|null $validatedApproval Pre-validated approval, reused instead of re-validating
     * @return \Crustum\Ai\Gateway\ApprovalResumption
     */
    protected function resumeFromApproval(array $approval, array $messages, array $tools, ?array $validatedApproval = null, ?RunContext $context = null): ApprovalResumption
    {
        $messages = $this->settleAbandonedToolCalls($messages, exceptLatestAssistantTurn: true);

        [$approvalResults, $shouldContinue, $failedToolCallIds] = $this->resolveApprovalResults($approval, $messages, $tools, $validatedApproval, $context);

        $newMessages = [];

        if (Value::filled($approvalResults)) {
            [$messages, $newMessages] = $this->appendApprovalResults($messages, $approvalResults);
        }

        return new ApprovalResumption(
            messages: $messages,
            newMessages: $newMessages,
            results: $approvalResults,
            failedToolCallIds: $failedToolCallIds,
            shouldContinue: $shouldContinue,
        );
    }

    /**
     * Resolve the approval decisions into tool results.
     *
     * @param array<string, \Crustum\Ai\Approvals\Decision> $approval Approval decisions keyed by tool call id
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     * @param array{0: \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall>, 1: array<string, \Crustum\Ai\Contracts\Tool|null>}|null $validatedApproval Pre-validated approval, reused instead of re-validating
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return array{0: array<int, \Crustum\Ai\Responses\Data\ToolResult>, 1: bool, 2: array<int, string>}
     */
    protected function resolveApprovalResults(array $approval, array $messages, array $tools, ?array $validatedApproval = null, ?RunContext $context = null): array
    {
        [$pendingToolCalls, $resolvedTools] = $validatedApproval ?? $this->validateApproval($approval, $messages, $tools);

        $toolResults = [];
        $failedToolCallIds = [];
        $hasBareRejection = false;

        foreach ($pendingToolCalls as $toolCall) {
            $decision = $approval[$toolCall->id]
                ?? $approval['*']
                ?? Decision::reject('The user rejected this tool call.');

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
            $tool = $resolvedTools[$toolCall->id];

            if (!$tool instanceof Tool) {
                throw new NoSuchToolException($toolCall->name);
            }

            try {
                $result = $this->executeTool($tool, $arguments, $toolCall->id, $context);
            } catch (Throwable $exception) {
                $failedToolCallIds[] = $toolCall->id;
                $result = 'The tool call failed: ' . $exception->getMessage();
            }

            $toolResults[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $arguments,
                $result,
                $toolCall->resultId,
            );
        }

        return [$toolResults, !$hasBareRejection, $failedToolCallIds];
    }

    /**
     * Validate the approval's decisions against the pending tool calls, throwing on any mismatch.
     *
     * @param array<string, \Crustum\Ai\Approvals\Decision> $approval Approval decisions keyed by tool call id
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
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
            $result->providerContentBlocks,
        );
    }

    /**
     * Build a step from a response and optional tool results.
     *
     * @param \Crustum\Ai\Gateway\StepResponse $result Step response
     * @param array<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Tool results
     * @return \Crustum\Ai\Responses\Data\Step
     */
    protected function buildStep(StepResponse $result, array $toolResults = []): Step
    {
        return (new Step(
            $result->text,
            $result->toolCalls,
            $toolResults,
            $result->finishReason,
            $result->usage,
            $result->meta,
        ))->withRawResponse($result->raw);
    }

    /**
     * Build the final text response from all generated steps.
     *
     * @param \Cake\Collection\CollectionInterface $steps Generated steps
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

        $totalUsage = $steps->reduce(
            fn(Usage $carry, Step $step): Usage => $carry->add($step->usage),
            new Usage(),
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
            )->withSteps($steps)->withRawResponse($lastResult->raw);
        }

        return (new TextResponse(
            $finalStep->text,
            $totalUsage,
            $finalStep->meta,
        ))->withMessages($messages)->withSteps($steps)->withRawResponse($lastResult?->raw);
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
