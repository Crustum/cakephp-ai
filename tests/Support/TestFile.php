<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support;

use Laminas\Diactoros\UploadedFile;
use RuntimeException;

/**
 * Test file utilities.
 */
class TestFile
{
    /**
     * Create an uploaded file instance for feature tests.
     *
     * @param string $path Absolute file path
     * @param string|null $clientFilename Client filename
     * @param string|null $mimeType MIME type
     * @return \Laminas\Diactoros\UploadedFile
     */
    public static function upload(
        string $path,
        ?string $clientFilename = null,
        ?string $mimeType = null,
    ): UploadedFile {
        $clientFilename ??= basename($path);
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException(sprintf('Unable to open uploaded file at [%s].', $path));
        }

        return new UploadedFile(
            $stream,
            filesize($path) ?: 0,
            UPLOAD_ERR_OK,
            $clientFilename,
            $mimeType ?? mime_content_type($path) ?: 'application/octet-stream',
        );
    }
}
