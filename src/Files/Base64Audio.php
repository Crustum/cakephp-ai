<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\PendingResponses\PendingTranscriptionGeneration;
use InvalidArgumentException;
use JsonSerializable;
use Laminas\Diactoros\UploadedFile;

/**
 * Base64-encoded audio file.
 *
 * Represents an audio file encoded as Base64 string.
 *
 * @phpstan-consistent-constructor
 */
class Base64Audio extends Audio implements JsonSerializable, StorableFile, TranscribableAudio
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
            throw new InvalidArgumentException('Base64 audio content cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Create a new instance from an uploaded file.
     *
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file
     * @param string|null $mimeType MIME type override
     */
    public static function fromUpload(UploadedFile $file, ?string $mimeType = null): static
    {
        $content = $file->getStream()->getContents();

        return new static(
            base64_encode($content),
            $mimeType ?? $file->getClientMediaType(),
        );
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
     * Generate a transcription of the given audio.
     *
     * @return \Crustum\Ai\PendingResponses\PendingTranscriptionGeneration
     */
    public function transcription(): PendingTranscriptionGeneration
    {
        return new PendingTranscriptionGeneration($this);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'base64-audio',
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
