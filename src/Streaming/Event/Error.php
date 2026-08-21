<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

/**
 * Error event.
 *
 * Represents an error that occurred during streaming.
 */
class Error extends StreamEvent
{
    /**
     *  Error type
     *
     * @var string
     */
    public string $type;

    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param string|int $type Error type
     * @param string $message Error message
     * @param bool $recoverable Whether the error is recoverable
     * @param int $timestamp Unix timestamp
     * @param array<string, mixed>|null $metadata Optional metadata
     */
    public function __construct(
        public string $id,
        string|int $type,
        public string $message,
        public bool $recoverable,
        public int $timestamp,
        public ?array $metadata = null,
    ) {
        $this->type = (string)$type;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => $this->type,
            'message' => $this->message,
            'recoverable' => $this->recoverable,
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
            'type' => 'error',
            'errorText' => $this->message,
        ];
    }
}
