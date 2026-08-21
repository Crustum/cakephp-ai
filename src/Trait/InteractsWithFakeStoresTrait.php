<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Cake\Collection\CollectionInterface;
use Closure;
use Crustum\Ai\Contracts\Files\HasProviderId;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files;
use Crustum\Ai\Gateway\FakeStoreGateway;
use Crustum\Ai\Stores;
use DateInterval;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Interacts With Fake Stores Trait
 *
 * Provides methods for faking store operations in tests.
 * Allows recording and asserting store creation, deletion, and file management for testing purposes.
 */
trait InteractsWithFakeStoresTrait
{
    /**
     * The fake store gateway instance.
     */
    protected ?FakeStoreGateway $fakeStoreGateway = null;

    /**
     * All of the recorded store creations.
     */
    protected array $recordedStoreCreations = [];

    /**
     * All of the recorded store deletions.
     *
     * @var array<string>
     */
    protected array $recordedStoreDeletions = [];

    /**
     * All of the recorded file additions.
     */
    protected array $recordedFileAdditions = [];

    /**
     * All of the recorded file removals.
     */
    protected array $recordedFileRemovals = [];

    /**
     * Fake store operations.
     *
     * @param \Closure|array $responses Responses to return
     * @return \Crustum\Ai\Gateway\FakeStoreGateway
     */
    public function fakeStores(Closure|array $responses = []): FakeStoreGateway
    {
        $this->recordedStoreCreations = [];
        $this->recordedStoreDeletions = [];
        $this->recordedFileAdditions = [];
        $this->recordedFileRemovals = [];

        return $this->fakeStoreGateway = new FakeStoreGateway($responses);
    }

    /**
     * Record a store creation.
     *
     * @param string $name Store name
     * @param string|null $description Store description
     * @param \Cake\Collection\CollectionInterface|null $fileIds File IDs
     * @param \DateInterval|null $expiresWhenIdleFor Expiration interval
     * @return $this
     */
    public function recordStoreCreation(
        string $name,
        ?string $description = null,
        ?CollectionInterface $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ) {
        $this->recordedStoreCreations[] = [
            'name' => $name,
            'description' => $description,
            'fileIds' => $fileIds,
            'expiresWhenIdleFor' => $expiresWhenIdleFor,
        ];

        return $this;
    }

    /**
     * Record a store deletion.
     *
     * @param string $storeId Store ID
     * @return $this
     */
    public function recordStoreDeletion(string $storeId)
    {
        $this->recordedStoreDeletions[] = $storeId;

        return $this;
    }

    /**
     * Record a file addition to a store.
     *
     * @param string $storeId Store ID
     * @param string $fileId File ID
     * @param \Crustum\Ai\Contracts\Files\StorableFile|\Laminas\Diactoros\UploadedFile|\Crustum\Ai\Contracts\Files\HasProviderId|string $file The file
     * @return $this
     */
    public function recordFileAddition(
        string $storeId,
        string $fileId,
        StorableFile|UploadedFile|HasProviderId|string $file,
    ) {
        $this->recordedFileAdditions[] = [
            'storeId' => $storeId,
            'fileId' => $fileId,
            'file' => $file,
        ];

        return $this;
    }

    /**
     * Record a file removal from a store.
     *
     * @param string $storeId Store ID
     * @param string $fileId File ID
     * @return $this
     */
    public function recordFileRemoval(string $storeId, string $fileId)
    {
        $this->recordedFileRemovals[] = [
            'storeId' => $storeId,
            'fileId' => $fileId,
        ];

        return $this;
    }

    /**
     * Assert that a store was created matching a given truth test.
     *
     * @param \Closure|string $callback Truth test callback or store name
     * @return $this
     */
    public function assertStoreCreated(Closure|string $callback)
    {
        if (is_string($callback)) {
            $name = $callback;
            $callback = fn($n): bool => $n === $name;
        }

        PHPUnit::assertTrue(
            collection($this->recordedStoreCreations)->some(fn(array $creation) => $callback(
                $creation['name'],
                $creation['description'],
                $creation['fileIds'],
                $creation['expiresWhenIdleFor'],
            )),
            'An expected store creation was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a store was not created matching a given truth test.
     *
     * @param \Closure|string $callback Truth test callback or store name
     * @return $this
     */
    public function assertStoreNotCreated(Closure|string $callback)
    {
        if (is_string($callback)) {
            $name = $callback;
            $callback = fn($n): bool => $n === $name;
        }

        PHPUnit::assertFalse(
            collection($this->recordedStoreCreations)->some(fn(array $creation) => $callback(
                $creation['name'],
                $creation['description'],
                $creation['fileIds'],
                $creation['expiresWhenIdleFor'],
            )),
            'An unexpected store creation was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no stores were created.
     *
     * @return $this
     */
    public function assertNoStoresCreated()
    {
        PHPUnit::assertEmpty(
            $this->recordedStoreCreations,
            'Unexpected store creations were recorded.',
        );

        return $this;
    }

    /**
     * Assert that a store was deleted matching a given truth test.
     *
     * @param \Closure|string $callback Truth test callback or store ID
     * @return $this
     */
    public function assertStoreDeleted(Closure|string $callback)
    {
        if (is_string($callback)) {
            $storeId = $callback;
            $callback = fn($id): bool => $id === $storeId;
        }

        PHPUnit::assertTrue(
            collection($this->recordedStoreDeletions)->some(fn(string $id) => $callback($id)),
            'An expected store deletion was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a store was not deleted matching a given truth test.
     *
     * @param \Closure|string $callback Truth test callback or store ID
     * @return $this
     */
    public function assertStoreNotDeleted(Closure|string $callback)
    {
        if (is_string($callback)) {
            $storeId = $callback;
            $callback = fn($id): bool => $id === $storeId;
        }

        PHPUnit::assertFalse(
            collection($this->recordedStoreDeletions)->some(fn(string $id) => $callback($id)),
            'An unexpected store deletion was recorded.',
        );

        return $this;
    }

    /**
     * Assert that no stores were deleted.
     *
     * @return $this
     */
    public function assertNoStoresDeleted()
    {
        PHPUnit::assertEmpty(
            $this->recordedStoreDeletions,
            'Unexpected store deletions were recorded.',
        );

        return $this;
    }

    /**
     * Assert that a file was added to a store matching a given truth test.
     *
     * @param \Closure|string $storeId Store ID or callback
     * @param \Closure|string|null $fileId File ID
     * @return $this
     */
    public function assertFileAddedToStore(Closure|string $storeId, Closure|string|null $fileId = null)
    {
        $callback = $this->fileMatchingCallback($storeId, $fileId);

        PHPUnit::assertTrue(
            collection($this->recordedFileAdditions)->some(fn(array $addition) => $callback($addition['storeId'], $addition['file'])),
            'An expected file addition was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a file was not added to a store matching a given truth test.
     *
     * @param \Closure|string $storeId Store ID or callback
     * @param \Closure|string|null $fileId File ID
     * @return $this
     */
    public function assertFileNotAddedToStore(Closure|string $storeId, Closure|string|null $fileId = null)
    {
        $callback = $this->fileMatchingCallback($storeId, $fileId);

        PHPUnit::assertFalse(
            collection($this->recordedFileAdditions)->some(fn(array $addition) => $callback($addition['storeId'], $addition['file'])),
            'An unexpected file addition was recorded.',
        );

        return $this;
    }

    /**
     * Assert that a file was removed from a store matching a given truth test.
     *
     * @param \Closure|string $storeId Store ID or callback
     * @param \Closure|string|null $fileId File ID
     * @return $this
     */
    public function assertFileRemovedFromStore(Closure|string $storeId, Closure|string|null $fileId = null)
    {
        $callback = $this->fileMatchingCallback($storeId, $fileId);

        PHPUnit::assertTrue(
            collection($this->recordedFileRemovals)->some(fn(array $removal) => $callback($removal['storeId'], $removal['fileId'])),
            'An expected file removal was not recorded.',
        );

        return $this;
    }

    /**
     * Assert that a file was not removed from a store matching a given truth test.
     *
     * @param \Closure|string $storeId Store ID or callback
     * @param \Closure|string|null $fileId File ID
     * @return $this
     */
    public function assertFileNotRemovedFromStore(Closure|string $storeId, Closure|string|null $fileId = null)
    {
        $callback = $this->fileMatchingCallback($storeId, $fileId);

        PHPUnit::assertFalse(
            collection($this->recordedFileRemovals)->some(fn(array $removal) => $callback($removal['storeId'], $removal['fileId'])),
            'An unexpected file removal was recorded.',
        );

        return $this;
    }

    /**
     * Get a callback for matching store and file IDs.
     *
     * @param \Closure|string $storeId Store ID or callback
     * @param string|null $fileId File ID
     * @return \Closure
     */
    protected function fileMatchingCallback(Closure|string $storeId, Closure|string|null $fileId = null): Closure
    {
        if ($storeId instanceof Closure) {
            return $storeId;
        }

        if ($fileId instanceof Closure) {
            return $fileId;
        }

        $expectedStoreId = str_starts_with($storeId, 'fake_store_') ? $storeId : Stores::fakeId($storeId);
        $expectedFileId = $fileId !== null ? (str_starts_with($fileId, 'fake_file_') ? $fileId : Files::fakeId($fileId))
            : null;

        return fn($s, $f): bool => $s === $expectedStoreId && $this->fileIdMatches($f, $expectedFileId);
    }

    /**
     * Determine if the given file matches the expected file ID.
     *
     * @param \Crustum\Ai\Contracts\Files\StorableFile|\Laminas\Diactoros\UploadedFile|\Crustum\Ai\Contracts\Files\HasProviderId|string $file The file
     * @param string|null $expectedFileId Expected file ID
     * @return bool
     */
    protected function fileIdMatches(StorableFile|UploadedFile|HasProviderId|string $file, ?string $expectedFileId): bool
    {
        if ($expectedFileId === null) {
            return true;
        }

        return match (true) {
            $file instanceof HasProviderId => $file->id() === $expectedFileId,
            is_string($file) => $file === $expectedFileId,
            default => false,
        };
    }

    /**
     * Determine if store operations are faked.
     *
     * @return bool
     */
    public function storesAreFaked(): bool
    {
        return $this->fakeStoreGateway !== null;
    }

    /**
     * Get the fake store gateway.
     */
    public function fakeStoreGateway(): ?FakeStoreGateway
    {
        return $this->fakeStoreGateway;
    }
}
