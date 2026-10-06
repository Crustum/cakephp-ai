<?php
declare(strict_types=1);

namespace Crustum\Ai\PendingResponses;

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Event\ProviderFailedOver;
use Crustum\Ai\Exception\FailoverableException;
use Crustum\Ai\Files\LocalAudio;
use Crustum\Ai\Files\StoredAudio;
use Crustum\Ai\Job\GenerateTranscriptionJob;
use Crustum\Ai\Job\PendingDispatch;
use Crustum\Ai\PendingResponses\Trait\ResolvesProviderOptionsTrait;
use Crustum\Ai\Prompts\QueuedTranscriptionPrompt;
use Crustum\Ai\Providers\Provider as AbstractProvider;
use Crustum\Ai\Responses\QueuedTranscriptionResponse;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Trait\ConditionableTrait;
use LogicException;

/**
 * Pending transcription generation request.
 *
 * Builder for configuring and executing audio transcription requests.
 * Supports language specification, diarization, timeout configuration,
 * and both synchronous and queued execution.
 */
class PendingTranscriptionGeneration
{
    use ConditionableTrait;
    use ResolvesProviderOptionsTrait;

    /**
     * The language (ISO-639-1) of the audio being transcribed.
     */
    protected ?string $language = null;

    /**
     * Whether the transcript should be diarized.
     */
    protected bool $diarize = false;

    /**
     * The timeout (in seconds) for the transcription generation.
     */
    protected int $timeout = 30;

    /**
     * Constructor.
     *
     * @param \Crustum\Ai\Contracts\Files\TranscribableAudio $audio The audio to transcribe
     */
    public function __construct(
        protected TranscribableAudio $audio,
    ) {
    }

    /**
     * Specify the language (ISO-639-1) of the audio being transcribed.
     *
     * @param string $language ISO-639-1 language code
     */
    public function language(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    /**
     * Indicate that the transcript should be diarized.
     *
     * @param bool $diarize Whether to diarize
     */
    public function diarize(bool $diarize = true): static
    {
        $this->diarize = $diarize;

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the transcription generation.
     *
     * @param int $seconds Timeout in seconds
     */
    public function timeout(int $seconds = 30): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Generate the transcription.
     *
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider or array of providers
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\TranscriptionResponse
     * @throws \Crustum\Ai\Exception\FailoverableException if every configured provider fails to generate the transcription.
     */
    public function generate(Lab|array|string|null $provider = null, ?string $model = null): TranscriptionResponse
    {
        $providers = AbstractProvider::formatProviderAndModelList(
            $provider ?? Configure::read('Ai.default_for_transcription'),
            $model,
        );

        $lastException = null;

        foreach ($providers as $provider => $model) {
            $provider = Ai::manager()->fakeableTranscriptionProvider($provider);

            $model ??= $provider->defaultTranscriptionModel();

            [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($provider);

            $provider = $provider->withHeaders($headers);

            try {
                return $provider->transcribe($this->audio, $this->language, $this->diarize, $model, $this->timeout, $providerOptions);
            } catch (FailoverableException $e) {
                $lastException = $e;

                EventManager::instance()->dispatch(new ProviderFailedOver($provider->name(), $model, $e, $provider));

                continue;
            }
        }

        throw $lastException;
    }

    /**
     * Queue the generation of the transcription.
     *
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider or array of providers
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\QueuedTranscriptionResponse
     * @throws \LogicException if the audio attachment is not a local audio or an audio file stored on a filesystem disk.
     */
    public function queue(Lab|array|string|null $provider = null, ?string $model = null): QueuedTranscriptionResponse
    {
        if (
            !$this->audio instanceof StoredAudio &&
            !$this->audio instanceof LocalAudio
        ) {
            throw new LogicException('Only local audio or audio stored on a filesystem disk may be attachments for queued transcription generations.');
        }

        if (Ai::manager()->transcriptionsAreFaked()) {
            Ai::manager()->recordTranscriptionGeneration(
                new QueuedTranscriptionPrompt(
                    $this->audio,
                    $this->language,
                    $this->diarize,
                    $provider,
                    $model,
                    $this->timeout,
                    $this->queuedProviderOptions(),
                ),
            );
        }

        return new QueuedTranscriptionResponse(
            new PendingDispatch(
                GenerateTranscriptionJob::class,
                GenerateTranscriptionJob::payload($this, $provider, $model),
            ),
        );
    }
}
