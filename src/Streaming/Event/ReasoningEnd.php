<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

/**
 * Reasoning end event.
 *
 * Fired when AI reasoning/thinking phase completes.
 */
class ReasoningEnd extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string $reasoningId Reasoning session ID
     * @param int $timestamp Unix timestamp
     * @param array<string, mixed>|null $summary Optional summary
     */
    public function __construct(
        public string $id,
        public string $reasoningId,
        public int $timestamp,
        public ?array $summary = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'reasoning_end',
            'reasoning_id' => $this->reasoningId,
            'timestamp' => $this->timestamp,
            'summary' => $this->summary,
        ];
    }
}
