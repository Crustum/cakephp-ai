<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Closure;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Prompts\TranscriptionPrompt;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TranscriptionSegment;
use Crustum\Ai\Responses\Data\TranscriptionUsage;
use Crustum\Ai\Responses\TranscriptionResponse;
use RuntimeException;

/**
 * Fake Transcription Gateway
 *
 * Fake implementation of transcription gateway for testing purposes.
 * Allows simulating transcription operations without making actual API calls.
 */
class FakeTranscriptionGateway implements TranscriptionGateway
{
    /**
     * Current response index for array responses
     */
    protected int $currentResponseIndex = 0;

    /**
     * Whether to prevent generations without fake responses
     */
    protected bool $preventStrayGenerations = false;

    /**
     * Constructor.
     *
     * @param \Closure|array $responses Responses to return
     */
    public function __construct(protected Closure|array $responses = [])
    {
    }

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
    ): TranscriptionResponse {
        $transcriptionPrompt = new TranscriptionPrompt(
            $audio,
            $language,
            $diarize,
            $provider,
            $model,
            $timeout,
            $providerOptions,
        );

        return $this->nextResponse($provider, $model, $transcriptionPrompt);
    }

    /**
     * Get the next response instance.
     *
     * @param \Crustum\Ai\Contracts\Providers\TranscriptionProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\TranscriptionPrompt $prompt The transcription prompt
     * @return \Crustum\Ai\Responses\TranscriptionResponse
     */
    protected function nextResponse(
        TranscriptionProvider $provider,
        string $model,
        TranscriptionPrompt $prompt,
    ): TranscriptionResponse {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt);

        $result = $this->marshalResponse($response, $provider, $model, $prompt);
        $this->currentResponseIndex++;

        return $result;
    }

    /**
     * Marshal the given response into a full response instance.
     *
     * @param mixed $response The response to marshal
     * @param \Crustum\Ai\Contracts\Providers\TranscriptionProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\TranscriptionPrompt $prompt The transcription prompt
     * @return \Crustum\Ai\Responses\TranscriptionResponse
     */
    protected function marshalResponse(
        mixed $response,
        TranscriptionProvider $provider,
        string $model,
        TranscriptionPrompt $prompt,
    ): TranscriptionResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted transcription generation without a fake response.');
            }

            $response = 'Fake transcription text.';
        }

        if (is_string($response)) {
            return new TranscriptionResponse(
                $response,
                collection([
                    new TranscriptionSegment($response, 'Speaker 1', 0.0, 1.0),
                ]),
                new TranscriptionUsage(),
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any transcription generation is not faked.
     *
     * @param bool $prevent Whether to prevent stray generations
     */
    public function preventStrayTranscriptions(bool $prevent = true): static
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
