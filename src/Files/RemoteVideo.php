<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasRemoteContentTrait;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Remote video file.
 *
 * Represents a video file accessible via URL.
 */
class RemoteVideo extends Video implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;
    use HasRemoteContentTrait;

    /**
     * Constructor.
     *
     * @param string $url Video URL
     * @param string|null $mimeType MIME type
     * @throws \InvalidArgumentException if URL is empty
     */
    public function __construct(public string $url, ?string $mimeType = null)
    {
        if (empty(trim($url))) {
            throw new InvalidArgumentException('Remote video URL cannot be empty.');
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
            'type' => 'remote-video',
            'name' => $this->name(),
            'url' => $this->url,
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
}
