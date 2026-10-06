<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

use Cake\Collection\Collection;
use Crustum\Ai\Trait\JoinsReasoningTrait;

/**
 * Reasoning delta event.
 *
 * Represents an incremental chunk of reasoning/thinking content.
 */
class ReasoningDelta extends StreamEvent
{
    use JoinsReasoningTrait;

    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string $reasoningId Reasoning session ID
     * @param string $delta Reasoning delta chunk
     * @param int $timestamp Unix timestamp
     * @param array<string, mixed>|null $summary Optional summary
     */
    public function __construct(
        public string $id,
        public string $reasoningId,
        public string $delta,
        public int $timestamp,
        public ?array $summary = null,
    ) {
    }

    /**
     * Combine reasoning deltas by block, separating each block with a blank line.
     *
     * @param \Cake\Collection\Collection<int, \Crustum\Ai\Streaming\Event\StreamEvent>|array<\Crustum\Ai\Streaming\Event\StreamEvent> $events Events
     * @return string
     */
    public static function combine(Collection|array $events): string
    {
        $events = is_array($events) ? collection($events) : $events;

        /** @var \Cake\Collection\CollectionInterface<int, string> $texts */
        $texts = $events
            ->filter(fn($event): bool => $event instanceof ReasoningDelta)
            ->groupBy(fn(ReasoningDelta $event): string => $event->reasoningId)
            ->map(fn(array $deltas): string => implode('', collection($deltas)->map(fn(ReasoningDelta $event): string => $event->delta)->toList()));

        return static::joinReasoning($texts->toList());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'reasoning_delta',
            'reasoning_id' => $this->reasoningId,
            'delta' => $this->delta,
            'timestamp' => $this->timestamp,
            'summary' => $this->summary,
        ];
    }
}
