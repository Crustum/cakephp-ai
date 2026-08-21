<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasRemoteContentTrait;
use Crustum\Ai\PendingResponses\PendingTranscriptionGeneration;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Remote audio file.
 *
 * Represents an audio file accessible via URL.
 */
class RemoteAudio extends Audio implements JsonSerializable, StorableFile, TranscribableAudio
{
    use CanBeUploadedToProviderTrait;
    use HasRemoteContentTrait;

    /**
     * Constructor.
     *
     * @param string $url Audio URL
     * @param string|null $mimeType MIME type
     * @throws \InvalidArgumentException if URL is empty
     */
    public function __construct(public string $url, ?string $mimeType = null)
    {
        if (empty(trim($url))) {
            throw new InvalidArgumentException('Remote audio URL cannot be empty.');
        }

        $this->mime = $mimeType;
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
            'type' => 'remote-audio',
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
