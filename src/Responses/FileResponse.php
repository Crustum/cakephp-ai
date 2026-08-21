<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

/**
 * File Response
 *
 * Represents a file retrieved from an AI provider's file storage.
 */
class FileResponse
{
    /**
     * @var string|null The MIME type of the file.
     */
    public readonly ?string $mime;

    /**
     * Constructor
     *
     * @param string $id The provider-specific file ID.
     * @param string|null $mimeType The MIME type of the file.
     * @param string|null $content The file content (may be null if not retrieved).
     */
    public function __construct(
        public readonly string $id,
        ?string $mimeType = null,
        public readonly ?string $content = null,
    ) {
        $this->mime = $mimeType;
    }

    /**
     * Get the MIME type for the file.
     *
     * @return string|null The MIME type.
     */
    public function mimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the file's content.
     *
     * @return string|null The file content.
     */
    public function content(): ?string
    {
        return $this->content;
    }
}
