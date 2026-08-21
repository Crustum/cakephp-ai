<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Gateway\FileGateway;
use Crustum\Ai\Responses\FileResponse;
use Crustum\Ai\Responses\StoredFileResponse;

/**
 * File Provider Interface
 *
 * Defines contract for providers that support file storage and retrieval capabilities.
 */
interface FileProvider extends Provider
{
    /**
     * Get a file by its ID.
     *
     * @param string $fileId File ID
     * @return \Crustum\Ai\Responses\FileResponse
     */
    public function getFile(string $fileId): FileResponse;

    /**
     * Store the given file.
     *
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file File to store
     * @return \Crustum\Ai\Responses\StoredFileResponse
     */
    public function putFile(StorableFile $file): StoredFileResponse;

    /**
     * Delete a file by its ID.
     *
     * @param string $fileId File ID
     * @return void
     */
    public function deleteFile(string $fileId): void;

    /**
     * Get the provider's file gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\FileGateway
     */
    public function fileGateway(): FileGateway;

    /**
     * Set the provider's file gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\FileGateway $gateway File gateway
     * @return $this
     */
    public function useFileGateway(FileGateway $gateway);
}
