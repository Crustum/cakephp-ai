<?php
declare(strict_types=1);

namespace Crustum\Ai\Files\Trait;

use Override;

/**
 * Shared content access for files stored on a named filesystem operator.
 */
trait HasStoredContentTrait
{
    use ReadsStoredFilesystemTrait;

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
