<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

/**
 * Reasoning delta event.
 *
 * Represents an incremental chunk of reasoning/thinking content.
 */
class ReasoningDelta extends StreamEvent
{
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

    /**
     * @inheritDoc
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'reasoning-delta',
            'id' => $this->reasoningId,
            'delta' => $this->delta,
        ];
    }
}
