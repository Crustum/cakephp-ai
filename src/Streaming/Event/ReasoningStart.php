<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

/**
 * Reasoning start event.
 *
 * Fired when AI reasoning/thinking phase begins.
 */
class ReasoningStart extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string $reasoningId Reasoning session ID
     * @param int $timestamp Unix timestamp
     */
    public function __construct(
        public string $id,
        public string $reasoningId,
        public int $timestamp,
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
            'type' => 'reasoning_start',
            'reasoning_id' => $this->reasoningId,
            'timestamp' => $this->timestamp,
        ];
    }
}
