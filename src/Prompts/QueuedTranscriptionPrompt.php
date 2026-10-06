<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Enums\Lab;

/**
 * Queued transcription generation prompt.
 *
 * Represents a transcription request that has been queued
 * for asynchronous processing.
 */
class QueuedTranscriptionPrompt
{
    /**
     * Constructor.
     *
     * @param \Crustum\Ai\Contracts\Files\TranscribableAudio $audio The audio to transcribe
     * @param string|null $language Language code (ISO-639-1)
     * @param bool $diarize Whether to diarize the transcription
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider specification
     * @param string|null $model Model identifier
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     */
    public function __construct(
        public readonly TranscribableAudio $audio,
        public readonly ?string $language,
        public readonly bool $diarize,
        public readonly Lab|array|string|null $provider,
        public readonly ?string $model,
        public readonly int $timeout = 30,
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
