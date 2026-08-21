<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Files;

/**
 * Interface for files that have a MIME type.
 *
 * This interface provides methods to get and set the MIME type of a file,
 * allowing for proper content type identification and handling.
 */
interface HasMimeType
{
    /**
     * Get the file's MIME type.
     *
     * @return string|null The MIME type (e.g., 'image/jpeg', 'application/pdf') or null if not set
     */
    public function mimeType(): ?string;

    /**
     * Set the file's MIME type.
     *
     * Returns a new instance with the specified MIME type.
     *
     * @param string $mimeType The MIME type to set
     * @return $this A new instance with the updated MIME type
     */
    public function withMimeType(string $mimeType): static;
}
