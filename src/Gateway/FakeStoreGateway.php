<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Cake\Collection\CollectionInterface;
use Closure;
use Crustum\Ai\Contracts\Gateway\StoreGateway;
use Crustum\Ai\Contracts\Providers\StoreProvider;
use Crustum\Ai\Responses\Data\StoreFileCounts;
use Crustum\Ai\Store;
use Crustum\Ai\Stores;
use DateInterval;
use RuntimeException;

/**
 * Fake Store Gateway
 *
 * Fake implementation of store gateway for testing purposes.
 * Allows simulating store operations without making actual API calls.
 */
class FakeStoreGateway implements StoreGateway
{
    /**
     * Current response index for array responses
     */
    protected int $currentResponseIndex = 0;

    /**
     * Whether to prevent operations without fake responses
     */
    protected bool $preventStrayOperations = false;

    /**
     * Constructor.
     *
     * @param \Closure|array $responses Responses to return
     */
    public function __construct(protected Closure|array $responses = [])
    {
    }

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
    ): Store {
        return $this->nextGetResponse($provider, $storeId);
    }

    /**
     * Get the next response for a get request.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider The provider
     * @param string $storeId The store ID
     * @return \Crustum\Ai\Store
     */
    protected function nextGetResponse(StoreProvider $provider, string $storeId): Store
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $storeId);

        $result = $this->marshalGetResponse($provider, $response, $storeId);
        $this->currentResponseIndex++;

        return $result;
    }

    /**
     * Marshal the given response into a Store instance.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider The provider
     * @param mixed $response The response to marshal
     * @param string $storeId The store ID
     * @return \Crustum\Ai\Store
     */
    protected function marshalGetResponse(StoreProvider $provider, mixed $response, string $storeId): Store
    {
        if ($response instanceof Closure) {
            $response = $response($storeId);
        }

        if (is_null($response)) {
            if ($this->preventStrayOperations) {
                throw new RuntimeException('Attempted store retrieval without a fake response.');
            }

            /** @var \Crustum\Ai\Contracts\Providers\FileProvider&\Crustum\Ai\Contracts\Providers\StoreProvider $provider */
            return new Store(
                provider: $provider,
                id: $storeId,
                name: 'fake-store',
                fileCounts: new StoreFileCounts(0, 0, 0),
                ready: true,
            );
        }

        if (is_string($response)) {
            /** @var \Crustum\Ai\Contracts\Providers\FileProvider&\Crustum\Ai\Contracts\Providers\StoreProvider $provider */
            return new Store(
                provider: $provider,
                id: $storeId,
                name: $response,
                fileCounts: new StoreFileCounts(0, 0, 0),
                ready: true,
            );
        }

        return $response;
    }

    /**
     * Create a new vector store.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider The store provider instance
     * @param string $name The name of the store
     * @param string|null $description Optional store description
     * @param \Cake\Collection\CollectionInterface|null $fileIds Initial file IDs to add to the store
     * @param \DateInterval|null $expiresWhenIdleFor Expiration time when idle
     * @return \Crustum\Ai\Store
     */
    public function createStore(
        StoreProvider $provider,
        string $name,
        ?string $description = null,
        ?CollectionInterface $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store {
        /** @var \Crustum\Ai\Contracts\Providers\FileProvider&\Crustum\Ai\Contracts\Providers\StoreProvider $provider */
        return new Store(
            provider: $provider,
            id: Stores::fakeId($name),
            name: $name,
            fileCounts: new StoreFileCounts(0, 0, 0),
            ready: true,
        );
    }

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
    ): string {
        return $fileId;
    }

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
    ): bool {
        return true;
    }

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
    ): bool {
        return true;
    }

    /**
     * Indicate that an exception should be thrown if any store operation is not faked.
     *
     * @param bool $prevent Whether to prevent stray operations
     */
    public function preventStrayOperations(bool $prevent = true): static
    {
        $this->preventStrayOperations = $prevent;

        return $this;
    }
}
