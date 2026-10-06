<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Trait\CanBeUploadedToProviderTrait;
use Crustum\Ai\Files\Trait\HasLocalContentTrait;
use InvalidArgumentException;
use JsonSerializable;
use Laminas\Diactoros\UploadedFile;

/**
 * Local document file.
 *
 * Represents a document stored on the local filesystem.
 */
class LocalDocument extends Document implements JsonSerializable, StorableFile
{
    use CanBeUploadedToProviderTrait;
    use HasLocalContentTrait;

    /**
     * Constructor.
     *
     * @param string $path Local file path
     * @param string|null $mimeType MIME type
     * @throws \InvalidArgumentException
     */
    public function __construct(public string $path, ?string $mimeType = null, protected ?UploadedFile $upload = null)
    {
        if (empty(trim($path))) {
            throw new InvalidArgumentException('Document file path cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Create a document from an uploaded file, holding the upload so its temporary file is not discarded.
     *
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded file
     * @throws \InvalidArgumentException if the upload has no temporary path
     */
    public static function fromUploadedFile(UploadedFile $file): self
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Cannot store an uploaded file that failed to upload.');
        }

        $source = $file->getStream()->getMetadata('uri');

        if (!is_string($source) || trim($source) === '') {
            throw new InvalidArgumentException('Document file path cannot be empty.');
        }

        return (new self($source, $file->getClientMediaType(), $file))->as($file->getClientFilename());
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'local-document',
            'name' => $this->name(),
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }

    /**
     * Get the serializable representation, excluding the held upload.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = get_object_vars($this);

        unset($data['upload']);

        return $data;
    }

    /**
     * Restore the instance from its serialized representation.
     *
     * @param array<string, mixed> $data Serialized data
     * @return void
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
