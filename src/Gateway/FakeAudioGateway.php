<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Closure;
use Crustum\Ai\Contracts\Gateway\AudioGateway;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Prompts\AudioPrompt;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use RuntimeException;

/**
 * Fake Audio Gateway
 *
 * Fake implementation of audio gateway for testing purposes.
 * Allows simulating audio generation without making actual API calls.
 */
class FakeAudioGateway implements AudioGateway
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
     * Generate audio from the given text.
     *
     * @param \Crustum\Ai\Contracts\Providers\AudioProvider $provider The audio provider instance
     * @param string $model The model to use for audio generation
     * @param string $text The text to convert to audio
     * @param string $voice The voice to use for audio generation
     * @param string|null $instructions Optional instructions for audio generation
     * @param int $timeout Timeout in seconds (default: 30)
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\AudioResponse
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        $audioPrompt = new AudioPrompt($text, $voice, $instructions, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $audioPrompt);
    }

    /**
     * Get the next response instance.
     *
     * @param \Crustum\Ai\Contracts\Providers\AudioProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\AudioPrompt $prompt The audio prompt
     * @return \Crustum\Ai\Responses\AudioResponse
     */
    protected function nextResponse(AudioProvider $provider, string $model, AudioPrompt $prompt): AudioResponse
    {
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
     * @param \Crustum\Ai\Contracts\Providers\AudioProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\AudioPrompt $prompt The audio prompt
     * @return \Crustum\Ai\Responses\AudioResponse
     */
    protected function marshalResponse(
        mixed $response,
        AudioProvider $provider,
        string $model,
        AudioPrompt $prompt,
    ): AudioResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted audio generation without a fake response.');
            }

            $response = base64_encode('fake-audio-content');
        }

        if (is_string($response)) {
            return new AudioResponse($response, new Usage(), new Meta($provider->name(), $model));
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any audio generation is not faked.
     *
     * @param bool $prevent Whether to prevent stray generations
     */
    public function preventStrayAudio(bool $prevent = true): static
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
