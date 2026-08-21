<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Event\FileDeleted;
use Crustum\Ai\Event\FileStored;
use Crustum\Ai\Event\StoringFile;
use Crustum\Ai\Responses\FileResponse;
use Crustum\Ai\Responses\StoredFileResponse;

/**
 * Manages provider file operations.
 */
trait ManagesFilesTrait
{
    /**
     * Get a file by its ID.
     *
     * @param string $fileId File ID
     * @return \Crustum\Ai\Responses\FileResponse
     */
    public function getFile(string $fileId): FileResponse
    {
        return $this->fileGateway()->getFile($this, $fileId);
    }

    /**
     * Store the given file.
     *
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file File to store
     * @return \Crustum\Ai\Responses\StoredFileResponse
     */
    public function putFile(StorableFile $file): StoredFileResponse
    {
        $invocationId = Text::uuid();

        if (Ai::manager()->filesAreFaked()) {
            Ai::manager()->recordFileUpload($file);
        }

        $this->events->dispatch(new StoringFile(
            $invocationId,
            $this,
            $file,
        ));

        $response = $this->fileGateway()->putFile($this, $file);

        $this->events->dispatch(new FileStored(
            $invocationId,
            $this,
            $file,
            $response,
        ));

        return $response;
    }

    /**
     * Delete a file by its ID.
     *
     * @param string $fileId File ID
     * @return void
     */
    public function deleteFile(string $fileId): void
    {
        $invocationId = Text::uuid();

        if (Ai::manager()->filesAreFaked()) {
            Ai::manager()->recordFileDeletion($fileId);
        }

        $this->fileGateway()->deleteFile($this, $fileId);

        $this->events->dispatch(new FileDeleted(
            $invocationId,
            $this,
            $fileId,
        ));
    }
}
