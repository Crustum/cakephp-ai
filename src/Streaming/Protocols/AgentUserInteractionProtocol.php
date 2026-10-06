<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Protocols;

use Cake\Http\Response;
use Crustum\Ai\AgentUserInteraction\AgentUserInteraction;
use Crustum\Ai\Approvals\ApprovalMismatchException;
use Crustum\Ai\Http\Stream\AgentUserInteractionProtocolStreamResponse;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Streaming\Event\Citation;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\ProviderToolEvent;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamEvent;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Streaming\Event\ToolResult;
use Generator;
use function ulid;

/**
 * The Agent User Interaction (AG-UI) protocol.
 *
 * See: https://docs.ag-ui.com/concepts/events
 */
class AgentUserInteractionProtocol extends StreamProtocol
{
    /**
     * Current step number.
     */
    protected int $step = 0;

    /**
     * Whether the run finished.
     */
    protected bool $finished = false;

    /**
     * Response the protocol streams.
     */
    protected ?StreamableAgentResponse $response = null;

    /**
     * Constructor.
     *
     * @param string|null $threadId Thread identifier
     * @param string|null $runId Run identifier
     */
    public function __construct(
        protected ?string $threadId = null,
        protected ?string $runId = null,
    ) {
    }

    /**
     * Create an HTTP response that represents the given response using the protocol.
     *
     * @param \Crustum\Ai\Responses\StreamableAgentResponse $response Streamable response
     * @return \Cake\Http\Response
     */
    public function response(StreamableAgentResponse $response): Response
    {
        return new AgentUserInteractionProtocolStreamResponse($this->frames($response));
    }

    /**
     * Get the protocol parts that represent the given response's events.
     *
     * Resets and advances the run state machine while streaming.
     *
     * @param \Crustum\Ai\Responses\StreamableAgentResponse $response Streamable response
     * @return \Generator<int, array<string, mixed>>
     * @phpstan-impure
     */
    protected function parts(StreamableAgentResponse $response): Generator
    {
        $this->started = false;
        $this->errored = false;
        $this->finished = false;
        $this->step = 0;
        $this->response = $response;

        $this->runId ??= $response->invocationId;

        $usage = null;
        $reason = null;
        $provider = null;
        $model = null;

        foreach ($response as $event) {
            if ($this->finished) {
                continue;
            }

            if ($event instanceof StreamStart) {
                $provider = $event->provider;
                $model = $event->model;

                if ($this->started) {
                    yield $this->stepFinishedPart();
                    yield $this->startNextStepPart();
                } else {
                    yield from $this->beginRunParts();
                }

                continue;
            }

            if ($event instanceof Error) {
                $this->errored = true;
                $this->finished = true;
            }

            if ($event instanceof ToolApprovalRequest) {
                if (!$this->started) {
                    yield from $this->beginRunParts();
                }

                yield $this->stepFinishedPart();

                yield $this->runFinishedPart([
                    'outcome' => ['type' => 'interrupt', 'interrupts' => $this->interrupts($event)],
                ]);

                $this->finished = true;

                continue;
            }

            if ($event instanceof StreamEnd) {
                $usage = ($usage ?? new TextUsage())->add($event->usage);
                $reason = $event->reason;

                continue;
            }

            foreach ($this->mapEvent($event) as $part) {
                yield from $this->yieldPart($part);
            }
        }

        if ($this->started && !$this->errored && !$this->finished) {
            yield $this->stepFinishedPart();
            yield $this->runFinishedPart($this->completionAttributes($usage, $reason, $provider, $model));
        }
    }

    /**
     * @inheritDoc
     */
    protected function maskedErrorParts(): Generator
    {
        if ($this->finished) {
            return;
        }

        if ($this->failure instanceof ApprovalMismatchException) {
            yield from $this->yieldPart([
                'type' => 'RUN_ERROR',
                'message' => $this->failure->getMessage(),
                'code' => 'approval_mismatch',
            ]);

            return;
        }

        yield from $this->yieldPart(['type' => 'RUN_ERROR', 'message' => 'An error occurred.']);
    }

    /**
     * Get the given protocol part, preceded by the run started events when the run has not begun yet.
     *
     * @param array<string, mixed> $part Protocol part
     * @return \Generator<int, array<string, mixed>>
     * @phpstan-impure Emits the run started events on first call.
     */
    protected function yieldPart(array $part): Generator
    {
        if (!$this->started) {
            yield from $this->beginRunParts();
        }

        yield $part;
    }

    /**
     * Get the events that begin the run and its first step.
     *
     * @return \Generator<int, array<string, mixed>>
     * @phpstan-impure Marks the run started and resolves the thread id.
     */
    protected function beginRunParts(): Generator
    {
        $this->started = true;

        $this->threadId ??= $this->response->conversationId ?? ulid();

        yield [
            'type' => 'RUN_STARTED',
            'threadId' => $this->threadId,
            'runId' => $this->runId,
        ];

        yield $this->startNextStepPart();
    }

    /**
     * Get the event that starts the next step.
     *
     * @return array<string, mixed>
     * @phpstan-impure Advances the step counter.
     */
    protected function startNextStepPart(): array
    {
        return ['type' => 'STEP_STARTED', 'stepName' => (string)++$this->step];
    }

    /**
     * Get the event that finishes the current step.
     *
     * @return array<string, mixed>
     */
    protected function stepFinishedPart(): array
    {
        return ['type' => 'STEP_FINISHED', 'stepName' => (string)$this->step];
    }

    /**
     * Get the event that finishes the run with the given additional attributes.
     *
     * @param array<string, mixed> $attributes Additional attributes
     * @return array<string, mixed>
     */
    protected function runFinishedPart(array $attributes = []): array
    {
        $assistantMessageId = $this->response?->assistantMessageId;
        $userMessageId = $this->response?->userMessageId;

        return [
            'type' => 'RUN_FINISHED',
            'threadId' => $this->threadId,
            'runId' => $this->runId,
            ...($assistantMessageId ? ['messageId' => $assistantMessageId] : []),
            ...($userMessageId ? ['userMessageId' => $userMessageId] : []),
            ...$attributes,
        ];
    }

    /**
     * Get the run finished attributes that report how the run completed.
     *
     * @param \Crustum\Ai\Responses\Data\TextUsage|null $usage Combined usage
     * @param string|null $reason Finish reason
     * @param string|null $provider Provider name
     * @param string|null $model Model name
     * @return array<string, mixed>
     */
    protected function completionAttributes(?TextUsage $usage, ?string $reason, ?string $provider, ?string $model): array
    {
        return [
            ...($usage instanceof TextUsage ? ['usage' => [array_filter([
                'provider' => $provider,
                'model' => $model,
                'inputTokens' => $usage->inputTokens,
                'outputTokens' => $usage->outputTokens,
                'totalTokens' => $usage->totalTokens(),
                'reasoningTokens' => $usage->reasoningTokens,
                'cachedInputTokens' => $usage->cacheReadInputTokens,
            ], fn($value): bool => $value !== null)]] : []),
            ...($reason === null ? [] : ['metadata' => ['finishReason' => $reason]]),
        ];
    }

    /**
     * Get the interrupts that represent the given approval request's pending approvals.
     *
     * @param \Crustum\Ai\Streaming\Event\ToolApprovalRequest $event Approval request event
     * @return array<int, array<string, mixed>>
     */
    protected function interrupts(ToolApprovalRequest $event): array
    {
        $interrupts = [];

        foreach ($event->pendingApprovals as $approval) {
            $interrupts[] = AgentUserInteraction::interrupt(
                $approval->id,
                $approval->reason,
                $approval->tool,
                $approval->arguments,
            );
        }

        return $interrupts;
    }

    /**
     * Get the protocol parts that represent the given tool call.
     *
     * @param \Crustum\Ai\Responses\Data\ToolCall $call Tool call data
     * @return array<int, array<string, mixed>>
     */
    protected function toolCallParts(ToolCall $call): array
    {
        return [
            ['type' => 'TOOL_CALL_START', 'toolCallId' => $call->id, 'toolCallName' => $call->name],
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => $call->id, 'delta' => $this->json((object)$call->arguments)],
            ['type' => 'TOOL_CALL_END', 'toolCallId' => $call->id],
        ];
    }

    /**
     * Get the protocol parts that represent the given event.
     *
     * @param \Crustum\Ai\Streaming\Event\StreamEvent $event Stream event
     * @return array<int, array<string, mixed>>
     */
    protected function mapEvent(StreamEvent $event): array
    {
        return match (true) {
            $event instanceof TextStart => [[
                'type' => 'TEXT_MESSAGE_START',
                'messageId' => $event->messageId,
                'role' => 'assistant',
            ]],
            $event instanceof TextDelta => [[
                'type' => 'TEXT_MESSAGE_CONTENT',
                'messageId' => $event->messageId,
                'delta' => $event->delta,
            ]],
            $event instanceof TextEnd => [[
                'type' => 'TEXT_MESSAGE_END',
                'messageId' => $event->messageId,
            ]],
            $event instanceof ReasoningStart => [
                ['type' => 'REASONING_START', 'messageId' => $event->reasoningId],
                ['type' => 'REASONING_MESSAGE_START', 'messageId' => $event->reasoningId, 'role' => 'reasoning'],
            ],
            $event instanceof ReasoningDelta => [[
                'type' => 'REASONING_MESSAGE_CONTENT',
                'messageId' => $event->reasoningId,
                'delta' => $event->delta,
            ]],
            $event instanceof ReasoningEnd => [
                ['type' => 'REASONING_MESSAGE_END', 'messageId' => $event->reasoningId],
                ['type' => 'REASONING_END', 'messageId' => $event->reasoningId],
            ],
            $event instanceof ToolCallEvent => $this->toolCallParts($event->toolCall),
            $event instanceof ToolResult => [$event->preliminary
                ? $this->preliminaryToolResultPart($event)
                : $this->toolResultPart($event)],
            $event instanceof Error => [[
                'type' => 'RUN_ERROR',
                'message' => $event->message,
                'code' => $event->type,
            ]],
            $event instanceof Citation => $this->citationParts($event),
            $event instanceof ProviderToolEvent => [[
                'type' => 'CUSTOM',
                'name' => 'provider-tool',
                'value' => [
                    'provider' => $event->provider,
                    'itemId' => $event->itemId,
                    'type' => $event->type,
                    'data' => $event->data,
                    'status' => $event->status,
                ],
            ]],
            default => [],
        };
    }

    /**
     * Get the protocol part for a tool still producing its output, which AG-UI has no tool result for.
     *
     * @param \Crustum\Ai\Streaming\Event\ToolResult $event Tool result event
     * @return array<string, mixed>
     */
    protected function preliminaryToolResultPart(ToolResult $event): array
    {
        return [
            'type' => 'ACTIVITY_SNAPSHOT',
            'messageId' => $event->toolResult->id,
            'activityType' => 'TOOL_OUTPUT',
            'content' => [
                'toolName' => $event->toolResult->name,
                'output' => $event->toolResult->result,
            ],
        ];
    }

    /**
     * Get the protocol part that represents the given tool result event.
     *
     * @param \Crustum\Ai\Streaming\Event\ToolResult $event Tool result event
     * @return array<string, mixed>
     */
    protected function toolResultPart(ToolResult $event): array
    {
        $content = $this->toolResultContent($event);

        return [
            'type' => 'TOOL_CALL_RESULT',
            'messageId' => $event->toolResult->resultId ?? $event->id,
            'toolCallId' => $event->toolResult->id,
            'content' => $content,
            'role' => 'tool',
            ...($event->successful ? [] : ['metadata' => array_filter([
                'error' => $content,
                'denied' => $event->denied ? true : null,
            ], fn($value): bool => $value !== null)]),
        ];
    }

    /**
     * Get the protocol parts that represent the given citation event.
     *
     * @param \Crustum\Ai\Streaming\Event\Citation $event Citation event
     * @return array<int, array<string, mixed>>
     */
    protected function citationParts(Citation $event): array
    {
        return match (true) {
            $event->citation instanceof UrlCitation => [[
                'type' => 'CUSTOM',
                'name' => 'citation',
                'value' => array_filter([
                    'url' => $event->citation->url,
                    'title' => $event->citation->title,
                ], fn($value): bool => $value !== null),
            ]],
            default => [],
        };
    }

    /**
     * Get the tool message content for the given tool result event.
     *
     * @param \Crustum\Ai\Streaming\Event\ToolResult $event Tool result event
     * @return string
     */
    protected function toolResultContent(ToolResult $event): string
    {
        if (!$event->successful) {
            return $event->error ?? 'The tool call failed.';
        }

        return is_string($event->toolResult->result)
            ? $event->toolResult->result
            : $this->json($event->toolResult->result);
    }
}
