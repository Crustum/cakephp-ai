<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Streaming\Event\ToolCall;
use Crustum\Ai\Streaming\Event\ToolResult;
use Override;

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
        $toolResults = $events->filter(fn($e): bool => $e instanceof ToolResult)->map(fn($e) => $e->toolResult);

        $this->withToolCallsAndResults(
            toolCalls: $toolCalls,
            toolResults: $toolResults,
        );

        $this->events = $events;

        $pendingApprovals = $events
            ->filter(fn($e): bool => $e instanceof ToolApprovalRequest)
            ->unfold(fn(ToolApprovalRequest $event): CollectionInterface => $event->pendingApprovals)
            ->toList();

        $this->withPendingApprovals(collection($pendingApprovals));
    }

    /**
     * Get the raw provider replay state for the paused assistant turn, if any.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Override]
    public function pausedProviderContentBlocks(): array
    {
        /** @var \Crustum\Ai\Streaming\Event\ToolApprovalRequest|null $last */
        $last = $this->events
            ->filter(fn($e): bool => $e instanceof ToolApprovalRequest)
            ->last();

        if (!$last instanceof ToolApprovalRequest) {
            return [];
        }

        return $last->providerContentBlocks;
    }
}
