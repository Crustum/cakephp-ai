<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\StoreGateway;
use Crustum\Ai\Contracts\Providers\StoreProvider;
use Crustum\Ai\Gateway\Gemini\Trait\CreatesGeminiClientTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\Data\StoreFileCounts;
use Crustum\Ai\Store;
use DateInterval;

/**
 * Gemini file search store gateway.
 */
class GeminiStoreGateway implements StoreGateway
{
    use CreatesGeminiClientTrait;
    use HandlesFailoverErrorsTrait;

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Get a vector store by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider Store provider
     * @param string $storeId Store identifier
     * @return \Crustum\Ai\Store
     */
    public function getStore(StoreProvider $provider, string $storeId): Store
    {
        $storeId = $this->normalizeStoreId($storeId);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)->get($storeId),
        );

        $data = $response->getJson() ?? [];

        /** @var \Crustum\Ai\Contracts\Providers\FileProvider&\Crustum\Ai\Contracts\Providers\StoreProvider $provider */
        return new Store(
            provider: $provider,
            id: $data['name'],
            name: $data['displayName'] ?? null,
            fileCounts: new StoreFileCounts(
                completed: $data['activeDocumentsCount'] ?? 0,
                pending: $data['pendingDocumentsCount'] ?? 0,
                failed: $data['failedDocumentsCount'] ?? 0,
            ),
            ready: true,
        );
    }

    /**
     * Create a new vector store.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider Store provider
     * @param string $name Store name
     * @param string|null $description Optional description
     * @param \Cake\Collection\CollectionInterface|null $fileIds Initial file IDs
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
        $fileIds ??= new Collection([]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)->post('fileSearchStores', [
                'displayName' => $name,
            ]),
        );

        $data = $response->getJson() ?? [];

        $store = $this->getStore($provider, $data['name']);

        if (!$fileIds->isEmpty()) {
            foreach ($fileIds as $fileId) {
                $this->addFile($provider, $store->id, $fileId);
            }
        }

        return $store;
    }

    /**
     * Add a file to a vector store.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider Store provider
     * @param string $storeId Store identifier
     * @param string $fileId File identifier
     * @param array<string, mixed> $metadata File metadata
     * @return string
     */
    public function addFile(StoreProvider $provider, string $storeId, string $fileId, array $metadata = []): string
    {
        $storeId = $this->normalizeStoreId($storeId);
        $fileId = $this->normalizeFileId($fileId);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)->post("{$storeId}:importFile", array_filter([
                'fileName' => $fileId,
                'customMetadata' => $metadata === [] ? null : $this->formatMetadata($metadata),
            ])),
        );

        $data = $response->getJson() ?? [];

        return basename((string)($data['name'] ?? ''));
    }

    /**
     * Format metadata for Gemini's custom_metadata format.
     *
     * @param array<string, mixed> $metadata File metadata
     * @return array<int, array<string, mixed>>
     */
    protected function formatMetadata(array $metadata): array
    {
        return (new Collection($metadata))->map(fn(mixed $value, mixed $key): array => match (true) {
            is_numeric($value) => ['key' => $key, 'numericValue' => $value],
            is_array($value) => ['key' => $key, 'stringListValue' => ['values' => $value]],
            default => ['key' => $key, 'stringValue' => (string)$value],
        })->values()->toList();
    }

    /**
     * Remove a file from a vector store.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider Store provider
     * @param string $storeId Store identifier
     * @param string $documentId Document identifier
     * @return bool
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
    {
        $storeId = $this->normalizeStoreId($storeId);
        $documentId = $this->normalizeDocumentId($storeId, $documentId);

        $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)->delete($documentId, [
                'force' => true,
            ]),
        );

        return true;
    }

    /**
     * Delete a vector store by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\StoreProvider $provider Store provider
     * @param string $storeId Store identifier
     * @return bool
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        $storeId = $this->normalizeStoreId($storeId);

        $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)->delete($storeId),
        );

        return true;
    }

    /**
     * Normalize the store ID to include the resource prefix.
     *
     * @param string $storeId Store identifier
     * @return string
     */
    protected function normalizeStoreId(string $storeId): string
    {
        return str_starts_with($storeId, 'fileSearchStores/')
            ? $storeId
            : "fileSearchStores/{$storeId}";
    }

    /**
     * Normalize the file ID to include the resource prefix.
     *
     * @param string $fileId File identifier
     * @return string
     */
    protected function normalizeFileId(string $fileId): string
    {
        return str_starts_with($fileId, 'files/')
            ? $fileId
            : "files/{$fileId}";
    }

    /**
     * Normalize the document ID to include the full resource path.
     *
     * @param string $storeId Store identifier
     * @param string $documentId Document identifier
     * @return string
     */
    protected function normalizeDocumentId(string $storeId, string $documentId): string
    {
        if (str_starts_with($documentId, 'fileSearchStores/')) {
            return $documentId;
        }

        $documentId = match (true) {
            str_starts_with($documentId, 'documents/') => substr($documentId, 10),
            str_starts_with($documentId, 'files/') => substr($documentId, 6),
            default => $documentId,
        };

        return "{$storeId}/documents/{$documentId}";
    }
}
