<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use InvalidArgumentException;
use JsonSerializable;
use Override;
use RuntimeException;

/**
 * Local Image
 *
 * Represents an image stored on the local filesystem.
 */
class LocalImage extends Image implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;

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
