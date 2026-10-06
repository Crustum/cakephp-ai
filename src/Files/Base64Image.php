<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Base64 Image
 *
 * Represents an image encoded as Base64 data.
 * Useful for embedding images directly in JSON payloads.
 */
class Base64Image extends Image implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;

    /**
     * Constructor
     *
     * @param string $base64 The Base64-encoded image data.
     * @param string|null $mimeType The MIME type of the image.
     * @throws \InvalidArgumentException if base64 content is empty
     */
    public function __construct(
        public string $base64,
        ?string $mimeType = null,
    ) {
        if (empty($base64)) {
            throw new InvalidArgumentException('Base64 image content cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Get the raw representation of the file.
     *
     * @return string
     */
    public function content(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'base64-image',
            'name' => $this->name(),
            'base64' => $this->base64,
            'mime' => $this->mime,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Convert to string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
