<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Closure;
use Crustum\Ai\Contracts\Files\HasProviderId;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Contracts\Providers\StoreProvider;
use Crustum\Ai\Files\LocalDocument;
use Crustum\Ai\Files\ProviderDocument;
use Crustum\Ai\Responses\AddedDocumentResponse;
use Crustum\Ai\Responses\Data\StoreFileCounts;
use Laminas\Diactoros\UploadedFile;

/**
 * AI document store.
 *
 * Represents a collection of files/documents that can be used
 * for retrieval-augmented generation (RAG) or other AI operations.
 * Supports adding, removing, and managing documents in the store.
 */
class Store
{
    /**
     * Constructor.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider&\Crustum\Ai\Contracts\Providers\StoreProvider $provider The provider
     * @param string $id Store ID
     * @param string|null $name Store name
     * @param \Crustum\Ai\Responses\Data\StoreFileCounts $fileCounts File counts
     * @param bool $ready Whether the store is ready
     */
    public function __construct(
        protected FileProvider&StoreProvider $provider,
        public readonly string $id,
        public readonly ?string $name,
        public readonly StoreFileCounts $fileCounts,
        public readonly bool $ready,
    ) {
    }

    /**
     * Add a file to the store.
     *
     * @param \Crustum\Ai\Contracts\Files\StorableFile|\Laminas\Diactoros\UploadedFile|\Crustum\Ai\Contracts\Files\HasProviderId|string $file The file to add
     * @param array<string, mixed> $metadata File metadata
     * @return \Crustum\Ai\Responses\AddedDocumentResponse
     */
    public function add(
        StorableFile|UploadedFile|HasProviderId|string $file,
        array $metadata = [],
    ): AddedDocumentResponse {
        if ($file instanceof UploadedFile) {
            $file = LocalDocument::fromUploadedFile($file);
        }

        $originalFile = $file;

        if ($file instanceof StorableFile) {
            $file = $this->storeFile($file);
        }

        if (Ai::manager()->storesAreFaked()) {
            Ai::manager()->recordFileAddition($this->id, $file instanceof HasProviderId ? $file->id() : $file, $originalFile);
        }

        return new AddedDocumentResponse($this->provider->addFileToStore($this->id, match (true) {
            is_string($file) => new ProviderDocument($file),
            default => $file,
        }, $metadata), $file instanceof HasProviderId ? $file->id() : $file);
    }

    /**
     * Resolve the ID of the file a document was imported from, which Gemini does not reuse as the document ID.
     *
     * @param \Crustum\Ai\Contracts\Files\HasProviderId|string $documentId The document ID
     * @return string
     */
    protected function fileIdFor(HasProviderId|string $documentId): string
    {
        if ($documentId instanceof AddedDocumentResponse && $documentId->fileId() !== null) {
            return $documentId->fileId();
        }

        return $documentId instanceof HasProviderId ? $documentId->id() : $documentId;
    }

    /**
     * Store the given file with the provider.
     *
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file The file to store
     * @return \Crustum\Ai\Contracts\Files\HasProviderId
     */
    protected function storeFile(StorableFile $file): HasProviderId
    {
        return Files::put($file, provider: $this->provider->name());
    }

    /**
     * Remove a document from the store.
     *
     * @param \Crustum\Ai\Contracts\Files\HasProviderId|string $documentId The document ID
     * @param bool $deleteFile Whether to also delete the file from storage
     * @return bool
     */
    public function remove(HasProviderId|string $documentId, bool $deleteFile = false): bool
    {
        $removed = $this->provider->removeFileFromStore($this->id, $documentId);

        if ($deleteFile && $removed) {
            Files::delete(
                $this->fileIdFor($documentId),
                provider: $this->provider->name(),
            );
        }

        return $removed;
    }

    /**
     * Refresh the store from the provider.
     */
    public function refresh(): self
    {
        return $this->provider->getStore($this->id);
    }

    /**
     * Delete the store from the provider.
     *
     * @return bool
     */
    public function delete(): bool
    {
        return $this->provider->deleteStore($this->id);
    }

    /**
     * Assert that a file was added to the store.
     *
     * @param \Closure|string $fileId File ID or closure
     */
    public function assertAdded(Closure|string $fileId): static
    {
        Ai::manager()->assertFileAddedToStore($this->id, $this->fileAssertionCallback($fileId));

        return $this;
    }

    /**
     * Assert that a file was not added to the store.
     *
     * @param \Closure|string $fileId File ID or closure
     */
    public function assertNotAdded(Closure|string $fileId): static
    {
        Ai::manager()->assertFileNotAddedToStore($this->id, $this->fileAssertionCallback($fileId));

        return $this;
    }

    /**
     * Assert that a document was removed from the store.
     *
     * @param \Closure|string $documentId Document ID or closure
     */
    public function assertRemoved(Closure|string $documentId): static
    {
        if ($documentId instanceof Closure) {
            Ai::manager()->assertFileRemovedFromStore(fn(string $storeId, string $fileId): bool => $storeId === $this->id && $documentId($fileId));

            return $this;
        }

        Ai::manager()->assertFileRemovedFromStore($this->id, $documentId);

        return $this;
    }

    /**
     * Assert that a document was not removed from the store.
     *
     * @param \Closure|string $documentId Document ID or closure
     */
    public function assertNotRemoved(Closure|string $documentId): static
    {
        if ($documentId instanceof Closure) {
            Ai::manager()->assertFileNotRemovedFromStore(fn(string $storeId, string $fileId): bool => $storeId === $this->id && $documentId($fileId));

            return $this;
        }

        Ai::manager()->assertFileNotRemovedFromStore($this->id, $documentId);

        return $this;
    }

    /**
     * Get a callback for matching file assertions on this store.
     *
     * @param \Closure|string $fileId File ID or closure
     * @return \Closure
     */
    protected function fileAssertionCallback(Closure|string $fileId): Closure
    {
        if ($fileId instanceof Closure) {
            return fn($s, $f): bool => $s === $this->id && $fileId($f);
        }

        $expectedFileId = str_starts_with($fileId, 'fake_file_') ? $fileId : Files::fakeId($fileId);

        return fn($s, $f): bool => $s === $this->id && $this->fileIdMatches($f, $expectedFileId);
    }

    /**
     * Determine if the given file matches the expected file ID.
     *
     * @param \Crustum\Ai\Contracts\Files\StorableFile|\Crustum\Ai\Contracts\Files\HasProviderId|string $file The file
     * @param string $expectedFileId Expected file ID
     * @return bool
     */
    protected function fileIdMatches(
        StorableFile|HasProviderId|string $file,
        string $expectedFileId,
    ): bool {
        return match (true) {
            $file instanceof HasProviderId => $file->id() === $expectedFileId,
            is_string($file) => $file === $expectedFileId,
            default => false,
        };
    }
}
