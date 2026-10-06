<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Base64-encoded document.
 *
 * Represents a document encoded as Base64 string.
 *
 * @phpstan-consistent-constructor
 */
class Base64Document extends Document implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;

    /**
     * Constructor.
     *
     * @param string $base64 Base64-encoded content
     * @param string|null $mimeType MIME type
     * @throws \InvalidArgumentException if base64 content is empty
     */
    public function __construct(public string $base64, ?string $mimeType = null)
    {
        if (empty($base64)) {
            throw new InvalidArgumentException('Base64 document content cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Get the raw representation of the file.
     *
     * @return string
     */
    public function content(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'base64-document',
            'name' => $this->name(),
            'base64' => $this->base64,
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
