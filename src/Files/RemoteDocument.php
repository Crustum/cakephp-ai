<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasRemoteContentTrait;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Remote document file.
 *
 * Represents a document fetched from a remote HTTP(S) URL.
 */
class RemoteDocument extends Document implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;
    use HasRemoteContentTrait;

    /**
     * Constructor.
     *
     * @param string $url Document URL
     * @param string|null $mimeType MIME type
     * @throws \InvalidArgumentException
     */
    public function __construct(public string $url, ?string $mimeType = null)
    {
        if (empty(trim($url))) {
            throw new InvalidArgumentException('Remote document URL cannot be empty.');
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
            'type' => 'remote-document',
            'name' => $this->name(),
            'url' => $this->url,
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
}
