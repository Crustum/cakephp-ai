<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasStoredContentTrait;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Stored image file.
 *
 * Represents an image on a named League Flysystem operator.
 */
class StoredImage extends Image implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;
    use HasStoredContentTrait;

    /**
     * Constructor.
     *
     * @param string $path The storage path.
     * @param string|null $filesystem Named filesystem operator.
     * @throws \InvalidArgumentException If the path is empty.
     */
    public function __construct(
        public string $path,
        public ?string $filesystem = null,
    ) {
        if (empty(trim($path))) {
            throw new InvalidArgumentException('Image file path cannot be empty.');
        }
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'stored-image',
            'name' => $this->name(),
            'path' => $this->path,
            'filesystem' => $this->resolvedFilesystemName(),
        ];
    }
}
