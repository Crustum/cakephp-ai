<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Responses\ImageResponse;

/**
 * Image Provider Interface
 *
 * Defines contract for providers that support image generation capabilities.
 */
interface ImageProvider extends Provider
{
    /**
     * Generate an image.
     *
     * @param string $prompt Prompt for image generation
     * @param array<int, \Crustum\Ai\Files\Image> $attachments Image attachments
     * @param string|null $size Image size
     * @param 'low'|'medium'|'high'|null $quality Image quality
     * @param string|null $model Model to use for image generation
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
    ): ImageResponse;

    /**
     * Get the provider's image gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ImageGateway
     */
    public function imageGateway(): ImageGateway;

    /**
     * Set the provider's image gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\ImageGateway $gateway Image gateway
     * @return $this
     */
    public function useImageGateway(ImageGateway $gateway);

    /**
     * Get the name of the default image model.
     *
     * @return string
     */
    public function defaultImageModel(): string;

    /**
     * Get the default / normalized image options for the provider.
     *
     * @param string|null $size Image size
     * @param 'low'|'medium'|'high'|null $quality Image quality
     * @return array<string, mixed>
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array;
}
