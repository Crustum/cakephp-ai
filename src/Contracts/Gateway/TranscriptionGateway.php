<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Gateway;

use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Responses\TranscriptionResponse;

/**
 * Transcription Gateway Interface
 *
 * Defines methods for transcribing audio to text.
 */
interface TranscriptionGateway
{
    /**
     * Generate text from the given audio.
     *
     * @param \Crustum\Ai\Contracts\Providers\TranscriptionProvider $provider The transcription provider instance
     * @param string $model The model to use for transcription
     * @param \Crustum\Ai\Contracts\Files\TranscribableAudio $audio The audio to transcribe
     * @param string|null $language Optional language code for transcription
     * @param bool $diarize Whether to identify different speakers
     * @param int $timeout Timeout in seconds (default: 30)
     * @param array<string, mixed> $providerOptions Additional provider-specific options
     * @return \Crustum\Ai\Responses\TranscriptionResponse
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse;
}
