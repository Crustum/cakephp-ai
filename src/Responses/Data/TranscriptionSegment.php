<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;

/**
 * Transcription Segment
 *
 * Represents a segment of transcribed audio with speaker information and timing.
 */
class TranscriptionSegment implements JsonSerializable
{
    /**
     * Constructor
     *
     * @param string $text The transcribed text for this segment.
     * @param string $speaker The speaker identifier or name.
     * @param float $startSeconds The start time of the segment in seconds.
     * @param float $endSeconds The end time of the segment in seconds.
     */
    public function __construct(
        public string $text,
        public string $speaker,
        public float $startSeconds,
        public float $endSeconds,
    ) {
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed> The array representation.
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'speaker' => $this->speaker,
            'start_seconds' => $this->startSeconds,
            'end_seconds' => $this->endSeconds,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     *
     * @return array<string, mixed> The JSON serializable data.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
