<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Ai;
use Crustum\Ai\Event\GeneratingImage;
use Crustum\Ai\Event\ImageGenerated;
use Crustum\Ai\Prompts\ImagePrompt;
use Crustum\Ai\Responses\ImageResponse;

/**
 * Generates images through the provider's image gateway.
 */
trait GeneratesImagesTrait
{
    /**
     * Generate an image.
     *
     * @param string $prompt Image prompt
     * @param array<int, \Crustum\Ai\Files\Image> $attachments Image attachments
     * @param string|null $size Image size
     * @param 'low'|'medium'|'high'|null $quality Image quality
     * @param string|null $model Model name
     * @param int|null $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\ImageResponse
     */
    public function image(
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?string $model = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse {
        $invocationId = Text::uuid();

        $model ??= $this->defaultImageModel();

        $prompt = new ImagePrompt($prompt, $attachments, $size, $quality, $this, $model, $timeout, $providerOptions);

        if (Ai::manager()->imagesAreFaked()) {
            Ai::manager()->recordImageGeneration($prompt);
        }

        $this->events->dispatch(new GeneratingImage(
            $invocationId,
            $this,
            $model,
            $prompt,
        ));

        $response = $this->imageGateway()->generateImage(
            $this,
            $model,
            $prompt->prompt,
            $prompt->attachments->toList(),
            $prompt->size,
            $prompt->quality,
            $timeout,
            $prompt->providerOptions,
        );

        $this->events->dispatch(new ImageGenerated(
            $invocationId,
            $this,
            $model,
            $prompt,
            $response,
        ));

        return $response;
    }
}
