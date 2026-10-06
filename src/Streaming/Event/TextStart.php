<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

/**
 * Text start event.
 *
 * Fired when text generation begins in a streaming response.
 */
class TextStart extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string $messageId Message ID
     * @param int $timestamp Unix timestamp
     */
    public function __construct(
        public string $id,
        public string $messageId,
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
            'type' => 'text_start',
            'message_id' => $this->messageId,
            'timestamp' => $this->timestamp,
        ];
    }
}
