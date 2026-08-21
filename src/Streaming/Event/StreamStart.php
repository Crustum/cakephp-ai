<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

/**
 * Stream start event.
 *
 * Fired when a streaming AI response begins.
 */
class StreamStart extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string $provider Provider name
     * @param string $model Model name
     * @param int $timestamp Unix timestamp
     * @param array<string, mixed>|null $metadata Optional metadata
     */
    public function __construct(
        public string $id,
        public string $provider,
        public string $model,
        public int $timestamp,
        public ?array $metadata = null,
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
            'type' => 'stream_start',
            'provider' => $this->provider,
            'model' => $this->model,
            'timestamp' => $this->timestamp,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @inheritDoc
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'start',
            'messageId' => $this->id,
        ];
    }
}
