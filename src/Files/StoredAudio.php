<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasStoredContentTrait;
use Crustum\Ai\PendingResponses\PendingTranscriptionGeneration;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Stored audio file.
 *
 * Represents an audio file on a named League Flysystem operator.
 */
class StoredAudio extends Audio implements JsonSerializable, StorableFile, TranscribableAudio
{
    use CanBeUploadedToProviderTrait;
    use HasStoredContentTrait;

    /**
     * Constructor.
     *
     * @param string $path Storage path
     * @param string|null $filesystem Named filesystem operator
     * @throws \InvalidArgumentException if path is empty
     */
    public function __construct(public string $path, public ?string $filesystem = null)
    {
        if (empty(trim($path))) {
            throw new InvalidArgumentException('Audio file path cannot be empty.');
        }
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
            'type' => 'stored-audio',
            'name' => $this->name(),
            'path' => $this->path,
            'filesystem' => $this->resolvedFilesystemName(),
        ];
    }
}
