<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\ReadsStoredFilesystemTrait;
use InvalidArgumentException;
use JsonSerializable;
use Override;

/**
 * Stored document file.
 *
 * Represents a document on a named League Flysystem operator.
 */
class StoredDocument extends Document implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;
    use ReadsStoredFilesystemTrait;

    /**
     * Constructor.
     *
     * @param string $path Storage path
     * @param string|null $filesystem Named filesystem operator
     * @throws \InvalidArgumentException
     */
    public function __construct(public string $path, public ?string $filesystem = null)
    {
        if (empty(trim($path))) {
            throw new InvalidArgumentException('Document file path cannot be empty.');
        }
    }

    /**
     * Get the displayable name of the file.
     *
     * @return string|null
     */
    #[Override]
    public function name(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'stored-document',
            'name' => $this->name(),
            'path' => $this->path,
            'filesystem' => $this->resolvedFilesystemName(),
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
     * Convert the file to a string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
