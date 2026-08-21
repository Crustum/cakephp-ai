<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Cake\Collection\Collection;
use Crustum\Ai\Enums\Lab;

/**
 * Queued image generation prompt.
 *
 * Represents an image generation request that has been queued
 * for asynchronous processing.
 */
class QueuedImagePrompt
{
    /**
     * Image attachments.
     */
    public readonly Collection $attachments;

    /**
     * Constructor.
     *
     * @param string $prompt The image generation prompt
     * @param \Cake\Collection\Collection|array<mixed> $attachments Reference images
     * @param string|null $size Size/aspect ratio specification
     * @param string|null $quality Quality setting
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider specification
     * @param string|null $model Model identifier
     */
    public function __construct(
        public readonly string $prompt,
        Collection|array $attachments,
        public readonly ?string $size,
        public readonly ?string $quality,
        public readonly Lab|array|string|null $provider,
        public readonly ?string $model,
    ) {
        $this->attachments = $attachments instanceof Collection ? $attachments : collection($attachments);
    }

    /**
     * Determine if the prompt contains the given string.
     *
     * @param string $string String to search for
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
