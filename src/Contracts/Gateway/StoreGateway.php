<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Gateway;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Contracts\Providers\StoreProvider;
use Crustum\Ai\Store;
use DateInterval;

/**
 * Store Gateway Interface
 *
 * Defines methods for managing vector stores with AI providers.
 */
interface StoreGateway
{
    /**
     * Get a vector store by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider The store provider instance
     * @param string $storeId The store identifier
     * @return \Crustum\Ai\Store
     */
    public function getStore(
        StoreProvider $provider,
        string $storeId,
    ): Store;

    /**
     * Create a new vector store.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider The store provider instance
     * @param string $name The name of the store
     * @param string|null $description Optional store description
     * @param \Cake\Collection\CollectionInterface<int, string>|null $fileIds Initial file IDs to add to the store
     * @param \DateInterval|null $expiresWhenIdleFor Expiration time when idle
     * @return \Crustum\Ai\Store
     */
    public function createStore(
        StoreProvider $provider,
        string $name,
        ?string $description = null,
        ?CollectionInterface $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store;

    /**
     * Add a file to a vector store.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider The store provider instance
     * @param string $storeId The store identifier
     * @param string $fileId The file identifier to add
     * @param array<string, mixed> $metadata Additional metadata for the file
     * @return string The document identifier
     */
    public function addFile(
        StoreProvider $provider,
        string $storeId,
        string $fileId,
        array $metadata = [],
    ): string;

    /**
     * Remove a file from a vector store.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider The store provider instance
     * @param string $storeId The store identifier
     * @param string $documentId The document identifier to remove
     * @return bool True if the file was removed successfully
     */
    public function removeFile(
        StoreProvider $provider,
        string $storeId,
        string $documentId,
    ): bool;

    /**
     * Delete a vector store by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider The store provider instance
     * @param string $storeId The store identifier to delete
     * @return bool True if the store was deleted successfully
     */
    public function deleteStore(
        StoreProvider $provider,
        string $storeId,
    ): bool;
}
