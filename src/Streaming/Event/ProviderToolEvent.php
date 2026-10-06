<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

/**
 * Provider tool event.
 *
 * Represents a provider-specific tool event during streaming.
 */
class ProviderToolEvent extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string $itemId Item ID
     * @param string $type Event type
     * @param array<string, mixed> $data Event data
     * @param string $status Status
     * @param int $timestamp Unix timestamp
     * @param string $provider Provider name
     */
    public function __construct(
        public string $id,
        public string $itemId,
        public string $type,
        public array $data,
        public string $status,
        public int $timestamp,
        public string $provider,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->itemId,
            'type' => $this->type,
            'data' => $this->data,
            'status' => $this->status,
            'timestamp' => $this->timestamp,
            'provider' => $this->provider,
        ];
    }
}
