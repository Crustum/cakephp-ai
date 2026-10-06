<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use InvalidArgumentException;
use JsonSerializable;
use Override;

/**
 * S3 document reference.
 *
 * Represents a document stored in Amazon S3 for providers that accept S3 location references.
 */
class S3Document extends Document implements JsonSerializable
{
    /**
     * Constructor.
     *
     * @param string $url S3 URI (e.g. s3://bucket/path/file.pdf)
     * @param string|null $bucketOwner Optional bucket owner account ID
     * @param string|null $mimeType MIME type
     */
    public function __construct(
        public string $url,
        public ?string $bucketOwner = null,
        ?string $mimeType = null,
    ) {
        $this->mime = $mimeType;
    }

    /**
     * Get the displayable name of the file.
     */
    #[Override]
    public function name(): ?string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return $this->name ?? basename(is_string($path) ? $path : '');
    }

    /**
     * Get the raw bytes of the file.
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public function content(): string
    {
        throw new InvalidArgumentException(
            'S3Document cannot be read directly. It is only supported by providers that accept S3 location references. Use StoredDocument or RemoteDocument instead if you need to send the file contents inline.',
        );
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 's3-document',
            'name' => $this->name(),
            'url' => $this->url,
            'bucket_owner' => $this->bucketOwner,
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
