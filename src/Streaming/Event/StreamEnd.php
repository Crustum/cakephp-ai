<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

use Cake\Collection\Collection;
use Crustum\Ai\Responses\Data\Usage;

/**
 * Stream end event.
 *
 * Fired when a streaming AI response completes.
 */
class StreamEnd extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string $reason Completion reason
     * @param \Crustum\Ai\Responses\Data\Usage $usage Token usage
     * @param int $timestamp Unix timestamp
     */
    public function __construct(
        public string $id,
        public string $reason,
        public Usage $usage,
        public int $timestamp,
    ) {
    }

    /**
     * Combine the stream end usages in the given collection of events into a single usage instance.
     *
     * @param \Cake\Collection\Collection<\Crustum\Ai\Streaming\Event\StreamEvent>|array<\Crustum\Ai\Streaming\Event\StreamEvent> $events Events
     * @return \Crustum\Ai\Responses\Data\Usage
     */
    public static function combineUsage(Collection|array $events): Usage
    {
        $events = is_array($events) ? collection($events) : $events;

        return $events->filter(fn($event): bool => $event instanceof StreamEnd)
            ->map(fn(StreamEnd $event): Usage => $event->usage)
            ->reduce(fn($a, $b) => $a->add($b), new Usage());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'stream_end',
            'reason' => $this->reason,
            'usage' => $this->usage->toArray(),
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @inheritDoc
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'finish',
            'finishReason' => match ($this->reason) {
                'stop' => 'stop',
                'tool_calls' => 'tool-calls',
                'length' => 'length',
                'content_filter' => 'content-filter',
                'error' => 'error',
                'unknown' => 'other',
                default => 'other',
            },
            'messageMetadata' => [
                'usage' => [
                    'inputTokens' => $this->usage->promptTokens,
                    'outputTokens' => $this->usage->completionTokens,
                    'totalTokens' => $this->usage->promptTokens + $this->usage->completionTokens,
                    'reasoningTokens' => $this->usage->reasoningTokens,
                    'cachedInputTokens' => $this->usage->cacheReadInputTokens,
                ],
            ],
        ];
    }
}
