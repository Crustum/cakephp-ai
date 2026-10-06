<?php
declare(strict_types=1);

namespace Crustum\Ai\Files\Trait;

use Override;
use RuntimeException;

/**
 * Shared content access for files stored on the local filesystem.
 */
trait HasLocalContentTrait
{
    /**
     * Get the raw representation of the file.
     *
     * @return string
     * @throws \RuntimeException If the file does not exist at the configured path.
     */
    public function content(): string
    {
        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException(sprintf('File does not exist at path [%s]', $this->path));
        }

        return $content;
    }

    /**
     * Get the displayable name of the file.
     */
    #[Override]
    public function name(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    /**
     * Get the file's MIME type.
     */
    #[Override]
    public function mimeType(): ?string
    {
        if ($this->mime !== null) {
            return $this->mime;
        }

        if (!file_exists($this->path)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mimeType = finfo_file($finfo, $this->path);
        unset($finfo);

        return $mimeType !== false ? $mimeType : null;
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
