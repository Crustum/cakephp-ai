<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Event\GeneratingTranscription;
use Crustum\Ai\Event\TranscriptionGenerated;
use Crustum\Ai\Prompts\TranscriptionPrompt;
use Crustum\Ai\Responses\TranscriptionResponse;

/**
 * Generates transcriptions through the provider's transcription gateway.
 */
trait GeneratesTranscriptionsTrait
{
    /**
     * Transcribe the given audio to text.
     *
     * @param \Crustum\Ai\Contracts\Files\TranscribableAudio $audio Audio to transcribe
     * @param string|null $language Optional language code
     * @param bool $diarize Whether to diarize speakers
     * @param string|null $model Model name
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
    ): TranscriptionResponse {
        $invocationId = Text::uuid();

        $model ??= $this->defaultTranscriptionModel();

        $prompt = new TranscriptionPrompt($audio, $language, $diarize, $this, $model, $timeout, $providerOptions);

        if (Ai::manager()->transcriptionsAreFaked()) {
            Ai::manager()->recordTranscriptionGeneration($prompt);
        }

        $this->events->dispatch(new GeneratingTranscription(
            $invocationId,
            $this,
            $model,
            $prompt,
        ));

        $response = $this->transcriptionGateway()->generateTranscription(
            $this,
            $model,
            $prompt->audio,
            $prompt->language,
            $prompt->diarize,
            $prompt->timeout ?? 30,
            $prompt->providerOptions,
        );

        $this->events->dispatch(new TranscriptionGenerated(
            $invocationId,
            $this,
            $model,
            $prompt,
            $response,
        ));

        return $response;
    }
}
