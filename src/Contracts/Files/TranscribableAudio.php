<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Files;

use Crustum\Ai\PendingResponses\PendingTranscriptionGeneration;
use Stringable;

/**
 * Interface for audio files that can be transcribed.
 *
 * This interface extends file capabilities with transcription generation,
 * allowing audio content to be converted into text using AI providers.
 */
interface TranscribableAudio extends HasContent, HasMimeType, Stringable
{
    /**
     * Generate a transcription of the given audio.
     *
     * Creates a pending transcription request that can be further configured
     * and executed to convert the audio content into text.
     *
     * @return \Crustum\Ai\PendingResponses\PendingTranscriptionGeneration A pending transcription request
     */
    public function transcription(): PendingTranscriptionGeneration;
}
