<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Contracts\Providers\ImageProvider;

/**
 * Image Prompt Class
 *
 * Represents a prompt for image generation.
 */
class ImagePrompt
{
    /**
     * The prompt attachments.
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Files\Image>
     */
    public readonly CollectionInterface $attachments;

    /**
     * Create a new image prompt instance.
     *
     * @param string $prompt The prompt text
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Files\Image>|array<int, \Crustum\Ai\Files\Image> $attachments The attachments
     * @param string|null $size The image size/aspect ratio
     * @param string|null $quality The image quality
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider The image provider
     * @param string $model The model identifier
     * @param int|null $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     */
    public function __construct(
        public readonly string $prompt,
        CollectionInterface|array $attachments,
        public readonly ?string $size,
        public readonly ?string $quality,
        public readonly ImageProvider $provider,
        public readonly string $model,
        public readonly ?int $timeout = null,
        public readonly array $providerOptions = [],
    ) {
        $this->attachments = is_array($attachments) ? collection($attachments) : $attachments;
    }

    /**
     * Determine if the prompt contains the given string.
     *
     * @param string $string The string to search for
     * @return bool
     */
    public function contains(string $string): bool
    {
        return str_contains($this->prompt, $string);
    }

    /**
     * Determine if the image generation is square.
     *
     * @return bool
     */
    public function isSquare(): bool
    {
        return $this->size === '1:1';
    }

    /**
     * Determine if the image generation is landscape.
     *
     * @return bool
     */
    public function isLandscape(): bool
    {
        return $this->size === '3:2';
    }

    /**
     * Determine if the image generation is portrait.
     *
     * @return bool
     */
    public function isPortrait(): bool
    {
        return $this->size === '2:3';
    }
}
