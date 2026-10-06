<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasLocalContentTrait;
use Crustum\Ai\PendingResponses\PendingTranscriptionGeneration;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Local audio file.
 *
 * Represents an audio file stored on the local filesystem.
 */
class LocalAudio extends Audio implements JsonSerializable, StorableFile, TranscribableAudio
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
            throw new InvalidArgumentException('Audio file path cannot be empty.');
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
            'type' => 'local-audio',
            'name' => $this->name(),
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }
}
