<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TranscriptionUsage;
use Stringable;

/**
 * Transcription Response
 *
 * Represents a response containing audio transcription.
 */
class TranscriptionResponse implements Stringable
{
    /**
     * Transcribed text
     */
    public string $text;

    /**
     * Transcription segments
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\TranscriptionSegment>
     */
    public CollectionInterface $segments;

    /**
     * Token usage information
     */
    public TranscriptionUsage $usage;

    /**
     * Metadata about the response
     */
    public Meta $meta;

    /**
     * Constructor
     *
     * @param string $text The transcribed text
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\TranscriptionSegment> $segments Transcription segments
     * @param \Crustum\Ai\Responses\Data\TranscriptionUsage $usage Token usage information
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata about the response
     */
    public function __construct(
        string $text,
        CollectionInterface $segments,
        TranscriptionUsage $usage,
        Meta $meta,
    ) {
        $this->text = $text;
        $this->segments = $segments;
        $this->usage = $usage;
        $this->meta = $meta;
    }

    /**
     * Get the string representation of the transcription.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
