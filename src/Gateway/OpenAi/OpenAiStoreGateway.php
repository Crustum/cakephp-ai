<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAi;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Contracts\Gateway\StoreGateway;
use Crustum\Ai\Contracts\Providers\StoreProvider;
use Crustum\Ai\Gateway\OpenAi\Trait\CreatesOpenAiClientTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\Data\StoreFileCounts;
use Crustum\Ai\Store;
use Crustum\Ai\Utility\Value;
use DateInterval;
use DateTimeImmutable;

/**
 * OpenAI vector store API gateway.
 */
class OpenAiStoreGateway implements StoreGateway
{
    use CreatesOpenAiClientTrait;
    use HandlesFailoverErrorsTrait;

    /**
     * Get a vector store by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider Store provider
     * @param string $storeId Store ID
     * @return \Crustum\Ai\Store
     */
    public function getStore(StoreProvider $provider, string $storeId): Store
    {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->get('vector_stores/' . $storeId),
        );

        $data = $response->getJson() ?? [];

        /** @var \Crustum\Ai\Contracts\Providers\FileProvider&\Crustum\Ai\Contracts\Providers\StoreProvider $provider */
        return new Store(
            provider: $provider,
            id: (string)($data['id'] ?? $storeId),
            name: $data['name'] ?? null,
            fileCounts: new StoreFileCounts(
                completed: (int)($data['file_counts']['completed'] ?? 0),
                pending: (int)($data['file_counts']['in_progress'] ?? 0),
                failed: (int)($data['file_counts']['failed'] ?? 0),
            ),
            ready: ($data['status'] ?? '') === 'completed',
        );
    }

    /**
     * Create a new vector store.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider Store provider
     * @param string $name Store name
     * @param string|null $description Store description
     * @param \Cake\Collection\Collection|null $fileIds Initial file IDs
     * @param \DateInterval|null $expiresWhenIdleFor Idle expiration interval
     * @return \Crustum\Ai\Store
     */
    public function createStore(
        StoreProvider $provider,
        string $name,
        ?string $description = null,
        ?CollectionInterface $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store {
        $fileIds ??= collection([]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->post('vector_stores', array_filter([
                    'name' => $name,
                    'metadata' => Value::filled($description) ? ['description' => $description] : null,
                    'file_ids' => $fileIds->toList(),
                    'expires_after' => $expiresWhenIdleFor instanceof DateInterval ? [
                        'anchor' => 'last_active_at',
                        'days' => $this->intervalToDays($expiresWhenIdleFor),
                    ] : null,
                ])),
        );

        $data = $response->getJson() ?? [];

        return $this->getStore($provider, (string)($data['id'] ?? ''));
    }

    /**
     * Add a file to a vector store.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider Store provider
     * @param string $storeId Store ID
     * @param string $fileId File ID
     * @param array<string, mixed> $metadata File metadata
     * @return string Document ID
     */
    public function addFile(StoreProvider $provider, string $storeId, string $fileId, array $metadata = []): string
    {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->post(sprintf('vector_stores/%s/files', $storeId), array_filter([
                    'file_id' => $fileId,
                    'attributes' => Value::filled($metadata) ? $metadata : null,
                ])),
        );

        $data = $response->getJson() ?? [];

        return (string)($data['id'] ?? '');
    }

    /**
     * Remove a file from a vector store.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider Store provider
     * @param string $storeId Store ID
     * @param string $documentId Document ID
     * @return bool
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
    {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->delete(sprintf('vector_stores/%s/files/%s', $storeId, $documentId)),
        );

        $data = $response->getJson() ?? [];

        return (bool)($data['deleted'] ?? false);
    }

    /**
     * Delete a vector store by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider Store provider
     * @param string $storeId Store ID
     * @return bool
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->delete('vector_stores/' . $storeId),
        );

        $data = $response->getJson() ?? [];

        return (bool)($data['deleted'] ?? false);
    }

    /**
     * Convert a DateInterval to days.
     *
     * @param \DateInterval $interval Date interval
     * @return int
     */
    protected function intervalToDays(DateInterval $interval): int
    {
        $reference = new DateTimeImmutable();
        $end = $reference->add($interval);

        return max(1, (int)$reference->diff($end)->days);
    }
}
