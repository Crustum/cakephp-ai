<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasLocalContentTrait;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Local Image
 *
 * Represents an image stored on the local filesystem.
 */
class LocalImage extends Image implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;
    use HasLocalContentTrait;

    /**
     * Constructor
     *
     * @param string $path The local file path.
     * @param string|null $mimeType The MIME type of the image.
     * @throws \InvalidArgumentException If the path is empty.
     */
    public function __construct(
        public string $path,
        ?string $mimeType = null,
    ) {
        if (empty(trim($path))) {
            throw new InvalidArgumentException('Image file path cannot be empty.');
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
            'type' => 'local-image',
            'name' => $this->name(),
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }
}
