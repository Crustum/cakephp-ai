<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use Cake\Utility\Security;
use Crustum\Ai\Trait\StorableTrait;
use JsonSerializable;
use Stringable;

/**
 * Generated image data.
 *
 * Represents an AI-generated image with Base64 content and MIME type.
 * Supports storage to filesystem.
 */
class GeneratedImage implements JsonSerializable, Stringable
{
    use StorableTrait;

    /**
     * MIME type.
     */
    public ?string $mime = null;

    /**
     * Constructor.
     *
     * @param string $image Base64 representation of the image
     * @param string|null $mimeType MIME type
     */
    public function __construct(
        public string $image,
        ?string $mimeType = null,
    ) {
        $this->mime = $mimeType;
    }

    /**
     * Get the image's MIME type, falling back to a sensible default.
     *
     * @return string
     */
    public function mime(): string
    {
        return $this->mime ?: 'image/png';
    }

    /**
     * Get a default filename for the file.
     *
     * @return string
     */
    protected function randomStorageName(): string
    {
        static $name = null;
        if ($name === null) {
            $extension = match ($this->mime()) {
                'image/jpeg' => '.jpg',
                'image/png' => '.png',
                'image/webp' => '.webp',
                default => '.png',
            };
            $name = Security::randomString(40) . $extension;
        }

        return $name;
    }

    /**
     * Get the raw string content of the image.
     *
     * @return string
     */
    public function content(): string
    {
        return base64_decode($this->image);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'image' => $this->image,
            'mime' => $this->mime,
        ];
    }

    /**
     * Get the JSON serializable representation.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get the raw string content of the image.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
