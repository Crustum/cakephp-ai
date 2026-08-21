<?php
declare(strict_types=1);

namespace Crustum\Ai\Files\Trait;

use Crustum\Ai\Filesystem\FilesystemRegistry;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use RuntimeException;

/**
 * Reads Stored* attachment bytes through a named League Flysystem operator.
 */
trait ReadsStoredFilesystemTrait
{
    /**
     * Get the Flysystem operator for this stored file.
     *
     * @return \League\Flysystem\FilesystemOperator
     */
    protected function operator(): FilesystemOperator
    {
        return FilesystemRegistry::get($this->filesystem);
    }

    /**
     * Resolve the operator name used for serialization and error messages.
     *
     * @return string
     */
    protected function resolvedFilesystemName(): string
    {
        return $this->filesystem ?? FilesystemRegistry::default();
    }

    /**
     * Get the raw representation of the file.
     *
     * @return string
     * @throws \RuntimeException if the file cannot be read from the filesystem
     */
    public function content(): string
    {
        $name = $this->resolvedFilesystemName();

        try {
            return $this->operator()->read($this->path);
        } catch (FilesystemException $filesystemException) {
            throw new RuntimeException(
                sprintf('File [%s] does not exist on filesystem [%s].', $this->path, $name),
                0,
                $filesystemException,
            );
        }
    }

    /**
     * Get the file's MIME type.
     *
     * @return string|null
     */
    public function mimeType(): ?string
    {
        if ($this->mime !== null) {
            return $this->mime;
        }

        try {
            return $this->operator()->mimeType($this->path);
        } catch (FilesystemException) {
            return null;
        }
    }
}
