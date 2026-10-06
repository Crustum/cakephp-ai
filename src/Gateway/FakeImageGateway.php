<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Closure;
use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Prompts\ImagePrompt;
use Crustum\Ai\Responses\Data\GeneratedImage;
use Crustum\Ai\Responses\Data\ImageUsage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\ImageResponse;
use RuntimeException;

/**
 * Fake Image Gateway
 *
 * Fake implementation of image gateway for testing purposes.
 * Allows simulating image generation without making actual API calls.
 */
class FakeImageGateway implements ImageGateway
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
     * Generate an image.
     *
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider The image provider instance
     * @param string $model The model to use for image generation
     * @param string $prompt The prompt describing the desired image
     * @param array<int, \Crustum\Ai\Files\Image> $attachments Image attachments for reference
     * @param string|null $size Image size specification
     * @param 'low'|'medium'|'high'|null $quality Image quality level
     * @param int|null $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\ImageResponse
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse {
        $imagePrompt = new ImagePrompt($prompt, $attachments, $size, $quality, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $imagePrompt);
    }

    /**
     * Get the next response instance.
     *
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\ImagePrompt $prompt The image prompt
     * @return \Crustum\Ai\Responses\ImageResponse
     */
    protected function nextResponse(ImageProvider $provider, string $model, ImagePrompt $prompt): ImageResponse
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
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\ImagePrompt $prompt The image prompt
     * @return \Crustum\Ai\Responses\ImageResponse
     */
    protected function marshalResponse(
        mixed $response,
        ImageProvider $provider,
        string $model,
        ImagePrompt $prompt,
    ): ImageResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted image generation without a fake response.');
            }

            $response = base64_encode('fake-image-content');
        }

        if (is_string($response)) {
            return new ImageResponse(
                [new GeneratedImage($response, 'image/png')],
                new ImageUsage(),
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any image generation is not faked.
     *
     * @param bool $prevent Whether to prevent stray generations
     */
    public function preventStrayImages(bool $prevent = true): static
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
