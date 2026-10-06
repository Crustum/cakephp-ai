<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasStoredContentTrait;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Stored document file.
 *
 * Represents a document on a named League Flysystem operator.
 */
class StoredDocument extends Document implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;
    use HasStoredContentTrait;

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
}
