<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Responses\TranscriptionResponse;

/**
 * Transcription Provider Interface
 *
 * Defines contract for providers that support audio transcription (STT) capabilities.
 */
interface TranscriptionProvider extends Provider
{
    /**
     * Transcribe the given audio to text.
     *
     * @param \Crustum\Ai\Contracts\Files\TranscribableAudio $audio Audio to transcribe
     * @param string|null $language Language code for transcription
     * @param bool $diarize Whether to perform speaker diarization
     * @param string|null $model Model to use for transcription
     * @param int|null $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\TranscriptionResponse
     */
    public function transcribe(
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        ?string $model = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): TranscriptionResponse;

    /**
     * Get the provider's transcription gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\TranscriptionGateway
     */
    public function transcriptionGateway(): TranscriptionGateway;

    /**
     * Set the provider's transcription gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\TranscriptionGateway $gateway Transcription gateway
     * @return $this
     */
    public function useTranscriptionGateway(TranscriptionGateway $gateway);

    /**
     * Get the name of the default transcription (STT) model.
     *
     * @return string
     */
    public function defaultTranscriptionModel(): string;
}
