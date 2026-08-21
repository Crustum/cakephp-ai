<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Contracts\Files\HasProviderId;
use Crustum\Ai\Contracts\Gateway\StoreGateway;
use Crustum\Ai\Store;
use DateInterval;

/**
 * Store Provider Interface
 *
 * Defines contract for providers that support vector store management capabilities.
 */
interface StoreProvider extends Provider
{
    /**
     * Get a vector store by its ID.
     *
     * @param string $storeId Store ID
     * @return \Crustum\Ai\Store
     */
    public function getStore(string $storeId): Store;

    /**
     * Create a new vector store.
     *
     * @param string $name Store name
     * @param string|null $description Store description
     * @param \Cake\Collection\CollectionInterface|null $fileIds Collection of file IDs
     * @param \DateInterval|null $expiresWhenIdleFor Idle expiration interval
     * @return \Crustum\Ai\Store
     */
    public function createStore(
        string $name,
        ?string $description = null,
        ?CollectionInterface $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store;

    /**
     * Add a file to a vector store.
     *
     * @param string $storeId Store ID
     * @param \Crustum\Ai\Contracts\Files\HasProviderId $file File to add
     * @param array<string, mixed> $metadata File metadata
     * @return string File ID in the store
     */
    public function addFileToStore(string $storeId, HasProviderId $file, array $metadata = []): string;

    /**
     * Remove a file from a vector store.
     *
     * @param string $storeId Store ID
     * @param \Crustum\Ai\Contracts\Files\HasProviderId|string $fileId File ID to remove
     * @return bool
     */
    public function removeFileFromStore(string $storeId, HasProviderId|string $fileId): bool;

    /**
     * Delete a vector store by its ID.
     *
     * @param string $storeId Store ID
     * @return bool
     */
    public function deleteStore(string $storeId): bool;

    /**
     * Get the provider's store gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StoreGateway
     */
    public function storeGateway(): StoreGateway;

    /**
     * Set the provider's store gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\StoreGateway $gateway Store gateway
     * @return $this
     */
    public function useStoreGateway(StoreGateway $gateway);
}
