<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Closure;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Gateway\FakeFileGateway;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Interacts With Fake Files Trait
 *
 * Provides methods for faking file operations in tests.
 * Allows recording and asserting file upload and deletion for testing purposes.
 */
trait InteractsWithFakeFilesTrait
{
    /**
     * The fake file gateway instance.
     */
    protected ?FakeFileGateway $fakeFileGateway = null;

    /**
     * All of the recorded file uploads.
     */
    protected array $recordedFileUploads = [];

    /**
     * All of the recorded file deletions.
     *
     * @var array<string>
     */
    protected array $recordedFileDeletions = [];

    /**
     * Fake file operations.
     *
     * @param \Closure|array $responses Responses to return
     * @return \Crustum\Ai\Gateway\FakeFileGateway
     */
    public function fakeFiles(Closure|array $responses = []): FakeFileGateway
    {
        return $this->fakeFileGateway = new FakeFileGateway($responses);
    }

    /**
     * Record a file upload.
     *
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file The file
     * @return $this
     */
    public function recordFileUpload(StorableFile $file)
    {
        $this->recordedFileUploads[] = [
            'file' => $file,
        ];

        return $this;
    }

    /**
     * Record a file deletion.
     *
     * @param string $fileId File ID
     * @return $this
     */
    public function recordFileDeletion(string $fileId)
    {
        $this->recordedFileDeletions[] = $fileId;

        return $this;
    }

    /**
     * Assert that a file was uploaded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertFileUploaded(Closure $callback)
    {
        PHPUnit::assertTrue(
            collection($this->recordedFileUploads)->some(fn(array $upload) => $callback($upload['file'])),
            'An expected file upload was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a file was not uploaded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return $this
     */
    public function assertFileNotUploaded(Closure $callback)
    {
        PHPUnit::assertFalse(
            collection($this->recordedFileUploads)->some(fn(array $upload) => $callback($upload['file'])),
            'An unexpected file upload was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no files were uploaded.
     *
     * @return $this
     */
    public function assertNoFilesUploaded()
    {
        PHPUnit::assertEmpty(
            $this->recordedFileUploads,
            'Unexpected file uploads were recorded.',
        );

        return $this;
    }

    /**
     * Assert that a file was deleted matching a given truth test.
     *
     * @param \Closure|string $callback Truth test callback or file ID
     * @return $this
     */
    public function assertFileDeleted(Closure|string $callback)
    {
        if (is_string($callback)) {
            $fileId = $callback;
            $callback = fn($id): bool => $id === $fileId;
        }

        PHPUnit::assertTrue(
            collection($this->recordedFileDeletions)->some(fn(string $id) => $callback($id)),
            'An expected file deletion was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a file was not deleted matching a given truth test.
     *
     * @param \Closure|string $callback Truth test callback or file ID
     * @return $this
     */
    public function assertFileNotDeleted(Closure|string $callback)
    {
        if (is_string($callback)) {
            $fileId = $callback;
            $callback = fn($id): bool => $id === $fileId;
        }

        PHPUnit::assertFalse(
            collection($this->recordedFileDeletions)->some(fn(string $id) => $callback($id)),
            'An unexpected file deletion was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no files were deleted.
     *
     * @return $this
     */
    public function assertNoFilesDeleted()
    {
        PHPUnit::assertEmpty(
            $this->recordedFileDeletions,
            'Unexpected file deletions were recorded.',
        );

        return $this;
    }

    /**
     * Determine if file operations are faked.
     *
     * @return bool
     */
    public function filesAreFaked(): bool
    {
        return $this->fakeFileGateway !== null;
    }

    /**
     * Get the fake file gateway.
     */
    public function fakeFileGateway(): ?FakeFileGateway
    {
        return $this->fakeFileGateway;
    }
}
