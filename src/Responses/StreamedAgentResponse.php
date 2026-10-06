<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Streaming\Event\Citation;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Streaming\Event\ToolCall;
use Crustum\Ai\Streaming\Event\ToolResult;

/**
 * Streamed agent response.
 *
 * Represents a completed agent response that was originally streamed,
 * preserving all streaming events for replay or analysis.
 */
class StreamedAgentResponse extends AgentResponse
{
    /**
     * Streaming events.
     *
     * @var \Cake\Collection\Collection<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    public Collection $events;

    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Cake\Collection\Collection<int, \Crustum\Ai\Streaming\Event\StreamEvent> $events Stream events
     * @param \Crustum\Ai\Responses\Data\Meta $meta Response metadata
     */
    public function __construct(string $invocationId, Collection $events, Meta $meta)
    {
        parent::__construct(
            $invocationId,
            TextDelta::combine($events),
            StreamEnd::combineUsage($events),
            $meta,
        );

        /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls */
        $toolCalls = $events->filter(fn($e): bool => $e instanceof ToolCall)->map(fn($e) => $e->toolCall);
        /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults */
        $toolResults = $events->filter(fn($e): bool => $e instanceof ToolResult)
            ->reject(fn(ToolResult $event): bool => $event->preliminary)
            ->map(fn($e) => $e->toolResult);

        $this->withToolCallsAndResults(
            toolCalls: $toolCalls,
            toolResults: $toolResults,
        );

        $this->events = $events;

        $this->reasoning = ReasoningDelta::combine($events);
        $this->meta->citations = Citation::combine($events)->toList();

        $pendingApprovals = $events
            ->filter(fn($e): bool => $e instanceof ToolApprovalRequest)
            ->unfold(fn(ToolApprovalRequest $event): CollectionInterface => $event->pendingApprovals)
            ->toList();

        $this->withPendingApprovals(collection($pendingApprovals));

        $lastStepEvent = $events
            ->filter(fn($e): bool => $e instanceof StreamEnd || $e instanceof ToolApprovalRequest)
            ->last();
        $steps = collection([]);
        if ($lastStepEvent instanceof StreamEnd || $lastStepEvent instanceof ToolApprovalRequest) {
            $steps = $lastStepEvent->steps;
        }

        $this->withSteps($steps);
    }
}
