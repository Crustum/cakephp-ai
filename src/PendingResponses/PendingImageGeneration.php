<?php
declare(strict_types=1);

namespace Crustum\Ai\PendingResponses;

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Crustum\Ai\Ai;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Event\ProviderFailedOverEvent;
use Crustum\Ai\Exception\FailoverableException;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\StoredImage;
use Crustum\Ai\Job\GenerateImageJob;
use Crustum\Ai\Job\PendingDispatch;
use Crustum\Ai\Prompts\QueuedImagePrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\ImageResponse;
use Crustum\Ai\Responses\QueuedImageResponse;
use Crustum\Ai\Trait\ConditionableTrait;
use InvalidArgumentException;
use LogicException;

/**
 * Pending image generation request.
 *
 * Builder for configuring and executing image generation requests.
 * Supports size/aspect ratio configuration, quality settings,
 * attachments, and both synchronous and queued execution.
 */
class PendingImageGeneration
{
    use ConditionableTrait;

    /**
     * Reference images for the request.
     *
     * @var array<\Crustum\Ai\Files\Image>
     */
    public array $attachments = [];

    /**
     * The size / aspect ratio of the generated image.
     */
    public ?string $size = null;

    /**
     * The quality of the generated image.
     */
    public ?string $quality = null;

    /**
     * The timeout for the image generation request.
     */
    public ?int $timeout = null;

    /**
     * Constructor.
     *
     * @param string $prompt The prompt for image generation
     * @throws \InvalidArgumentException if prompt is blank
     */
    public function __construct(public string $prompt)
    {
        if (empty(trim($prompt))) {
            throw new InvalidArgumentException('A prompt is required to generate an image.');
        }
    }

    /**
     * Provide the reference images that should be sent with the request.
     *
     * @param array<\Crustum\Ai\Files\Image> $attachments Image attachments
     */
    public function attachments(array $attachments): static
    {
        $this->attachments = $attachments;

        return $this;
    }

    /**
     * Specify the size / aspect ratio of the generated image.
     *
     * @param string $size Size specification (e.g., "1:1", "16:9")
     */
    public function size(string $size): static
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Indicate that the generated image should have a square aspect ratio.
     */
    public function square(): static
    {
        $this->size = '1:1';

        return $this;
    }

    /**
     * Indicate that the generated image should have a portrait aspect ratio.
     */
    public function portrait(): static
    {
        $this->size = '2:3';

        return $this;
    }

    /**
     * Indicate that the generated image should have a landscape aspect ratio.
     */
    public function landscape(): static
    {
        $this->size = '3:2';

        return $this;
    }

    /**
     * Specify the quality of the generated image.
     *
     * @param 'low'|'medium'|'high' $quality Image quality
     */
    public function quality(string $quality): static
    {
        $this->quality = $quality;

        return $this;
    }

    /**
     * Specify the timeout for the image generation request.
     *
     * @param int|null $timeout Timeout in seconds
     */
    public function timeout(?int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Generate the image.
     *
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider or array of providers
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\ImageResponse
     * @throws \Crustum\Ai\Exception\FailoverableException if every configured provider fails to generate the image.
     */
    public function generate(Lab|array|string|null $provider = null, ?string $model = null): ImageResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? Configure::read('Ai.default_for_images'),
            $model,
        );

        $lastException = null;

        foreach ($providers as $provider => $model) {
            $provider = Ai::manager()->fakeableImageProvider($provider);

            $model ??= $provider->defaultImageModel();

            try {
                return $provider->image(
                    $this->prompt,
                    $this->attachments,
                    $this->size,
                    $this->quality,
                    $model,
                    $this->timeout,
                );
            } catch (FailoverableException $e) {
                $lastException = $e;

                EventManager::instance()->dispatch(new ProviderFailedOverEvent($provider->name(), $model, $e));

                continue;
            }
        }

        throw $lastException;
    }

    /**
     * Queue the generation of an image.
     *
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider or array of providers
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\QueuedImageResponse
     * @throws \LogicException if any attachment is not a local image or an image stored on a filesystem disk.
     */
    public function queue(Lab|array|string|null $provider = null, ?string $model = null): QueuedImageResponse
    {
        $this->ensureAttachmentsAreQueueable();

        if (Ai::manager()->imagesAreFaked()) {
            Ai::manager()->recordImageGeneration(
                new QueuedImagePrompt(
                    $this->prompt,
                    $this->attachments,
                    $this->size,
                    $this->quality,
                    $provider,
                    $model,
                ),
            );
        }

        return new QueuedImageResponse(
            new PendingDispatch(
                GenerateImageJob::class,
                GenerateImageJob::payload($this, $provider, $model),
            ),
        );
    }

    /**
     * Ensure all of the attachments are queueable.
     *
     * @return void
     * @throws \LogicException if any attachment is not queueable
     */
    protected function ensureAttachmentsAreQueueable(): void
    {
        foreach ($this->attachments as $attachment) {
            if (
                !$attachment instanceof StoredImage &&
                !$attachment instanceof LocalImage
            ) {
                throw new LogicException('Only local images or images stored on a filesystem disk may be attachments for queued image generations.');
            }
        }
    }
}
