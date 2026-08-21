<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Cake\Collection\CollectionInterface;
use Closure;
use Crustum\Ai\Gateway\FakeStoreGateway;
use DateInterval;

/**
 * Stores facade.
 *
 * Static interface for AI vector store operations.
 */
class Stores
{
    /**
     * Get a vector store by its ID.
     *
     * @param string $storeId Store ID
     * @param string|null $provider Provider name
     * @return \Crustum\Ai\Store
     */
    public static function get(string $storeId, ?string $provider = null): Store
    {
        return Ai::manager()->fakeableStoreProvider($provider)->getStore($storeId);
    }

    /**
     * Create a new vector store.
     *
     * @param string $name Store name
     * @param string|null $description Store description
     * @param \Cake\Collection\CollectionInterface<int, string>|array<int, string> $fileIds Initial file IDs
     * @param \DateInterval|null $expiresWhenIdleFor Idle expiration interval
     * @param string|null $provider Provider name
     * @return \Crustum\Ai\Store
     */
    public static function create(
        string $name,
        ?string $description = null,
        CollectionInterface|array $fileIds = [],
        ?DateInterval $expiresWhenIdleFor = null,
        ?string $provider = null,
    ): Store {
        $fileIds = $fileIds instanceof CollectionInterface
            ? $fileIds
            : collection(array_values($fileIds));

        return Ai::manager()->fakeableStoreProvider($provider)->createStore(
            $name,
            $description,
            $fileIds,
            $expiresWhenIdleFor,
        );
    }

    /**
     * Delete a vector store.
     *
     * @param string $storeId Store ID
     * @param string|null $provider Provider name
     * @return bool
     */
    public static function delete(string $storeId, ?string $provider = null): bool
    {
        return Ai::manager()->fakeableStoreProvider($provider)->deleteStore($storeId);
    }

    /**
     * Fake store operations.
     *
     * @param \Closure|array<int, mixed> $responses Fake responses
     * @param bool $files Whether to also fake file operations
     * @return \Crustum\Ai\Gateway\FakeStoreGateway
     */
    public static function fake(Closure|array $responses = [], bool $files = true): FakeStoreGateway
    {
        if ($files) {
            Files::fake();
        }

        return Ai::manager()->fakeStores($responses);
    }

    /**
     * Get the fake store ID for a given store name.
     *
     * @param string $for Store name
     * @return string
     */
    public static function fakeId(string $for): string
    {
        return 'fake_store_' . md5($for);
    }

    /**
     * Assert that a vector store was created by name.
     *
     * @param \Closure|string $callback Truth test callback or store name
     * @return void
     */
    public static function assertCreated(Closure|string $callback): void
    {
        Ai::manager()->assertStoreCreated($callback);
    }

    /**
     * Assert that a vector store was not created.
     *
     * @param \Closure|string $callback Truth test callback or store name
     * @return void
     */
    public static function assertNotCreated(Closure|string $callback): void
    {
        Ai::manager()->assertStoreNotCreated($callback);
    }

    /**
     * Assert that no vector stores were created.
     *
     * @return void
     */
    public static function assertNothingCreated(): void
    {
        Ai::manager()->assertNoStoresCreated();
    }

    /**
     * Assert that a vector store was deleted.
     *
     * @param \Closure|string $callback Truth test callback or store ID
     * @return void
     */
    public static function assertDeleted(Closure|string $callback): void
    {
        Ai::manager()->assertStoreDeleted($callback);
    }

    /**
     * Assert that a vector store was not deleted.
     *
     * @param \Closure|string $callback Truth test callback or store ID
     * @return void
     */
    public static function assertNotDeleted(Closure|string $callback): void
    {
        Ai::manager()->assertStoreNotDeleted($callback);
    }

    /**
     * Assert that no vector stores were deleted.
     *
     * @return void
     */
    public static function assertNothingDeleted(): void
    {
        Ai::manager()->assertNoStoresDeleted();
    }

    /**
     * Determine if store operations are faked.
     *
     * @return bool
     */
    public static function isFaked(): bool
    {
        return Ai::manager()->storesAreFaked();
    }
}
