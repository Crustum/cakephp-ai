<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasLocalContentTrait;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Local video file.
 *
 * Represents a video file stored on the local filesystem.
 */
class LocalVideo extends Video implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;
    use HasLocalContentTrait;

    /**
     * Constructor.
     *
     * @param string $path Local file path
     * @param string|null $mimeType MIME type
     * @throws \InvalidArgumentException if path is empty
     */
    public function __construct(public string $path, ?string $mimeType = null)
    {
        if (empty(trim($path))) {
            throw new InvalidArgumentException('Video file path cannot be empty.');
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
            'type' => 'local-video',
            'name' => $this->name(),
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
