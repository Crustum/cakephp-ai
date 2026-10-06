<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Crustum\Ai\Responses\Data\TextUsage;

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
     * @param \Crustum\Ai\Responses\Data\TextUsage $usage Token usage
     * @param int $timestamp Unix timestamp
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\Step> $steps Replay state for the completed turn; never serialized to clients
     */
    public function __construct(
        public string $id,
        public string $reason,
        public TextUsage $usage,
        public int $timestamp,
        public CollectionInterface $steps = new Collection([]),
    ) {
    }

    /**
     * Combine the stream end usages in the given collection of events into a single usage instance.
     *
     * @param \Cake\Collection\Collection<int, \Crustum\Ai\Streaming\Event\StreamEvent>|array<\Crustum\Ai\Streaming\Event\StreamEvent> $events Events
     * @return \Crustum\Ai\Responses\Data\TextUsage
     */
    public static function combineUsage(Collection|array $events): TextUsage
    {
        $events = is_array($events) ? collection($events) : $events;

        return $events->filter(fn($event): bool => $event instanceof StreamEnd)
            ->map(fn(StreamEnd $event): TextUsage => $event->usage)
            ->reduce(fn($a, $b) => $a->add($b), new TextUsage());
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
}
