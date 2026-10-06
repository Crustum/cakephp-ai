<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

/**
 * Resolves Document Filenames Trait
 *
 * Derives a filename for a nameless document from its MIME type.
 */
trait ResolvesDocumentFilenamesTrait
{
    /**
     * Get a fallback filename for the given MIME type.
     *
     * @param string|null $mimeType MIME type
     * @return string
     */
    protected function fallbackFilename(?string $mimeType): string
    {
        return 'document' . match ($mimeType) {
            'text/plain' => '.txt',
            'text/markdown' => '.md',
            'text/csv' => '.csv',
            'text/html' => '.html',
            'application/pdf' => '.pdf',
            'application/json' => '.json',
            default => '',
        };
    }
}
