<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Gateway;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Responses\FileResponse;
use Crustum\Ai\Responses\StoredFileResponse;

/**
 * File Gateway Interface
 *
 * Defines methods for managing files with AI providers.
 */
interface FileGateway
{
    /**
     * Get a file by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider The file provider instance
     * @param string $fileId The file identifier
     * @return \Crustum\Ai\Responses\FileResponse
     */
    public function getFile(
        FileProvider $provider,
        string $fileId,
    ): FileResponse;

    /**
     * Store the given file.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider The file provider instance
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file The file to store
     * @return \Crustum\Ai\Responses\StoredFileResponse
     */
    public function putFile(
        FileProvider $provider,
        StorableFile $file,
    ): StoredFileResponse;

    /**
     * Delete a file by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider The file provider instance
     * @param string $fileId The file identifier to delete
     * @return void
     */
    public function deleteFile(
        FileProvider $provider,
        string $fileId,
    ): void;
}
