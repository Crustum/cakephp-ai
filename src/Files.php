<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Closure;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Files\LocalDocument;
use Crustum\Ai\Gateway\FakeFileGateway;
use Crustum\Ai\Responses\FileResponse;
use Crustum\Ai\Responses\StoredFileResponse;
use Laminas\Diactoros\UploadedFile;

/**
 * Files Facade
 *
 * Static interface for AI file operations including upload, storage, and retrieval.
 */
class Files
{
    /**
     * Get a file by its ID
     *
     * @param string $fileId File identifier
     * @param string|null $provider Provider name
     * @return \Crustum\Ai\Responses\FileResponse
     */
    public static function get(string $fileId, ?string $provider = null): FileResponse
    {
        return Ai::manager()->fakeableFileProvider($provider)->getFile($fileId);
    }

    /**
     * Store the given file
     *
     * @param \Crustum\Ai\Contracts\Files\StorableFile|\Laminas\Diactoros\UploadedFile|string $file File to store
     * @param string|null $mimeType MIME type
     * @param string|null $name File name
     * @param string|null $provider Provider name
     * @return \Crustum\Ai\Responses\StoredFileResponse
     */
    public static function put(
        StorableFile|UploadedFile|string $file,
        ?string $mimeType = null,
        ?string $name = null,
        ?string $provider = null,
    ): StoredFileResponse {
        $file = match (true) {
            is_string($file) => new Base64Document(base64_encode($file), $mimeType),
            $file instanceof UploadedFile => LocalDocument::fromUploadedFile($file),
            default => $file,
        };

        if ($name !== null) {
            $file = $file->as($name);
        }

        if ($mimeType !== null) {
            $file = $file->withMimeType($mimeType);
        }

        return Ai::manager()->fakeableFileProvider($provider)->putFile($file);
    }

    /**
     * Store the file at the given local path
     *
     * @param string $path File path
     * @param string|null $mimeType MIME type
     * @param string|null $name File name
     * @param string|null $provider Provider name
     * @return \Crustum\Ai\Responses\StoredFileResponse
     */
    public static function putFromPath(string $path, ?string $mimeType = null, ?string $name = null, ?string $provider = null): StoredFileResponse
    {
        return static::put(Document::fromPath($path), $mimeType, $name, $provider);
    }

    /**
     * Store the file at the given path on the given filesystem
     *
     * @param string $path File path
     * @param string|null $filesystem Named filesystem operator
     * @param string|null $name File name
     * @param string|null $provider Provider name
     * @return \Crustum\Ai\Responses\StoredFileResponse
     */
    public static function putFromStorage(string $path, ?string $filesystem = null, ?string $name = null, ?string $provider = null): StoredFileResponse
    {
        return static::put(Document::fromStorage($path, $filesystem), null, $name, $provider);
    }

    /**
     * Delete a file by its ID
     *
     * @param string $fileId File identifier
     * @param string|null $provider Provider name
     * @return void
     */
    public static function delete(string $fileId, ?string $provider = null): void
    {
        Ai::manager()->fakeableFileProvider($provider)->deleteFile($fileId);
    }

    /**
     * Fake file operations
     *
     * @param \Closure|array<mixed> $responses Fake responses
     * @return \Crustum\Ai\Gateway\FakeFileGateway
     */
    public static function fake(Closure|array $responses = []): FakeFileGateway
    {
        return Ai::manager()->fakeFiles($responses);
    }

    /**
     * Get the fake file ID for a given store name
     *
     * @param string $for Identifier
     * @return string
     */
    public static function fakeId(string $for): string
    {
        return 'fake_file_' . md5($for);
    }

    /**
     * Assert that a file was stored matching a given truth test
     *
     * @param \Closure $callback Test callback
     * @return void
     */
    public static function assertStored(Closure $callback): void
    {
        Ai::manager()->assertFileUploaded($callback);
    }

    /**
     * Assert that a file was not stored matching a given truth test
     *
     * @param \Closure $callback Test callback
     * @return void
     */
    public static function assertNotStored(Closure $callback): void
    {
        Ai::manager()->assertFileNotUploaded($callback);
    }

    /**
     * Assert that no files were stored
     *
     * @return void
     */
    public static function assertNothingStored(): void
    {
        Ai::manager()->assertNoFilesUploaded();
    }

    /**
     * Assert that a file was deleted matching a given truth test
     *
     * @param \Closure|string $callback Test callback or file ID
     * @return void
     */
    public static function assertDeleted(Closure|string $callback): void
    {
        Ai::manager()->assertFileDeleted($callback);
    }

    /**
     * Assert that a file was not deleted matching a given truth test
     *
     * @param \Closure|string $callback Test callback or file ID
     * @return void
     */
    public static function assertNotDeleted(Closure|string $callback): void
    {
        Ai::manager()->assertFileNotDeleted($callback);
    }

    /**
     * Assert that no files were deleted
     *
     * @return void
     */
    public static function assertNothingDeleted(): void
    {
        Ai::manager()->assertNoFilesDeleted();
    }

    /**
     * Determine if file operations are faked
     *
     * @return bool
     */
    public static function isFaked(): bool
    {
        return Ai::manager()->filesAreFaked();
    }
}
