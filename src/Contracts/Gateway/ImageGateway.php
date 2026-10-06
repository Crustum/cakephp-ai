<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Gateway;

use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Responses\ImageResponse;

/**
 * Image Gateway Interface
 *
 * Defines methods for generating images using AI providers.
 */
interface ImageGateway
{
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
    ): ImageResponse;
}
