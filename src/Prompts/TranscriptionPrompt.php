<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;

/**
 * Transcription Prompt Class
 *
 * Represents a prompt for audio transcription.
 */
class TranscriptionPrompt
{
    /**
     * Create a new transcription prompt instance.
     *
     * @param \Crustum\Ai\Contracts\Files\TranscribableAudio $audio The audio to transcribe
     * @param string|null $language The language of the audio
     * @param bool $diarize Whether to diarize the audio (identify speakers)
     * @param \Crustum\Ai\Contracts\Providers\TranscriptionProvider $provider The transcription provider
     * @param string $model The model identifier
     * @param int|null $timeout The timeout in seconds
     * @param array<string, mixed> $providerOptions Additional provider-specific options
     */
    public function __construct(
        public readonly TranscribableAudio $audio,
        public readonly ?string $language,
        public readonly bool $diarize,
        public readonly TranscriptionProvider $provider,
        public readonly string $model,
        public readonly ?int $timeout = null,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if the transcription is diarized.
     *
     * @return bool
     */
    public function isDiarized(): bool
    {
        return $this->diarize;
    }
}
