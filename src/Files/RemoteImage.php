<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasRemoteContentTrait;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Remote Image
 *
 * Represents an image accessible via URL.
 */
class RemoteImage extends Image implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;
    use HasRemoteContentTrait;

    /**
     * Constructor
     *
     * @param string $url The image URL.
     * @param string|null $mimeType The MIME type of the image.
     * @throws \InvalidArgumentException If the URL is empty.
     */
    public function __construct(
        public string $url,
        ?string $mimeType = null,
    ) {
        if (empty(trim($url))) {
            throw new InvalidArgumentException('Remote image URL cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'remote-image',
            'name' => $this->name(),
            'url' => $this->url,
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
}
