<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Collection\CollectionInterface;
use Cake\Utility\Text;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Files\HasProviderId;
use Crustum\Ai\Event\AddingFileToStore;
use Crustum\Ai\Event\CreatingStore;
use Crustum\Ai\Event\FileAddedToStore;
use Crustum\Ai\Event\FileRemovedFromStore;
use Crustum\Ai\Event\RemovingFileFromStore;
use Crustum\Ai\Event\StoreCreated;
use Crustum\Ai\Event\StoreDeleted;
use Crustum\Ai\Store;
use DateInterval;

/**
 * Manages provider vector store operations.
 */
trait ManagesStoresTrait
{
    /**
     * Get a vector store by its ID.
     *
     * @param string $storeId Store ID
     * @return \Crustum\Ai\Store
     */
    public function getStore(string $storeId): Store
    {
        return $this->storeGateway()->getStore($this, $storeId);
    }

    /**
     * Create a new vector store.
     *
     * @param string $name Store name
     * @param string|null $description Store description
     * @param \Cake\Collection\CollectionInterface|null $fileIds Initial file IDs
     * @param \DateInterval|null $expiresWhenIdleFor Idle expiration interval
     * @return \Crustum\Ai\Store
     */
    public function createStore(
        string $name,
        ?string $description = null,
        ?CollectionInterface $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store {
        $invocationId = Text::uuid();

        $fileIds ??= collection([]);

        if (Ai::manager()->storesAreFaked()) {
            Ai::manager()->recordStoreCreation($name, $description, $fileIds, $expiresWhenIdleFor);
        }

        $this->events->dispatch(new CreatingStore(
            $invocationId,
            $this,
            $name,
            $description,
            $fileIds,
            $expiresWhenIdleFor,
        ));

        $store = $this->storeGateway()->createStore($this, $name, $description, $fileIds, $expiresWhenIdleFor);

        $this->events->dispatch(new StoreCreated(
            $invocationId,
            $this,
            $name,
            $description,
            $fileIds,
            $expiresWhenIdleFor,
            $store,
        ));

        return $store;
    }

    /**
     * Add a file to a vector store.
     *
     * @param string $storeId Store ID
     * @param \Crustum\Ai\Contracts\Files\HasProviderId $file File to add
     * @param array<string, mixed> $metadata File metadata
     * @return string Document ID
     */
    public function addFileToStore(string $storeId, HasProviderId $file, array $metadata = []): string
    {
        $invocationId = Text::uuid();

        $this->events->dispatch(new AddingFileToStore(
            $invocationId,
            $this,
            $storeId,
            $file->id(),
        ));

        $documentId = $this->storeGateway()->addFile($this, $storeId, $file->id(), $metadata);

        $this->events->dispatch(new FileAddedToStore(
            $invocationId,
            $this,
            $storeId,
            $file->id(),
            $documentId,
        ));

        return $documentId;
    }

    /**
     * Remove a file from a vector store.
     *
     * @param string $storeId Store ID
     * @param \Crustum\Ai\Contracts\Files\HasProviderId|string $documentId Document or file ID
     * @return bool
     */
    public function removeFileFromStore(string $storeId, HasProviderId|string $documentId): bool
    {
        $invocationId = Text::uuid();

        $documentId = $documentId instanceof HasProviderId ? $documentId->id() : $documentId;

        if (Ai::manager()->storesAreFaked()) {
            Ai::manager()->recordFileRemoval($storeId, $documentId);
        }

        $this->events->dispatch(new RemovingFileFromStore(
            $invocationId,
            $this,
            $storeId,
            $documentId,
        ));

        $result = $this->storeGateway()->removeFile($this, $storeId, $documentId);

        $this->events->dispatch(new FileRemovedFromStore(
            $invocationId,
            $this,
            $storeId,
            $documentId,
        ));

        return $result;
    }

    /**
     * Delete a vector store by its ID.
     *
     * @param string $storeId Store ID
     * @return bool
     */
    public function deleteStore(string $storeId): bool
    {
        $invocationId = Text::uuid();

        if (Ai::manager()->storesAreFaked()) {
            Ai::manager()->recordStoreDeletion($storeId);
        }

        $result = $this->storeGateway()->deleteStore($this, $storeId);

        $this->events->dispatch(new StoreDeleted(
            $invocationId,
            $this,
            $storeId,
        ));

        return $result;
    }
}
