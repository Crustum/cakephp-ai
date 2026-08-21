<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Closure;
use Crustum\Ai\Gateway\FakeEmbeddingGateway;
use Crustum\Ai\PendingResponses\PendingEmbeddingsGeneration;

/**
 * Embeddings facade.
 *
 * Static interface for AI embedding generation operations.
 */
class Embeddings
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param array<int, string|\Crustum\Ai\Files\Audio|\Crustum\Ai\Files\Document|\Crustum\Ai\Files\Image|\Crustum\Ai\Files\Video> $inputs Inputs to embed
     * @return \Crustum\Ai\PendingResponses\PendingEmbeddingsGeneration
     * @throws \InvalidArgumentException if the given inputs are not a list, are empty, or contain an unsupported input type
     */
    public static function for(array $inputs): PendingEmbeddingsGeneration
    {
        return new PendingEmbeddingsGeneration($inputs);
    }

    /**
     * Fake embeddings generation.
     *
     * @param \Closure|array<int, mixed> $responses Fake responses
     * @return \Crustum\Ai\Gateway\FakeEmbeddingGateway
     */
    public static function fake(Closure|array $responses = []): FakeEmbeddingGateway
    {
        return Ai::manager()->fakeEmbeddings($responses);
    }

    /**
     * Assert that embeddings were generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::manager()->assertEmbeddingsGenerated($callback);
    }

    /**
     * Assert that embeddings were not generated matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::manager()->assertEmbeddingsNotGenerated($callback);
    }

    /**
     * Assert that no embeddings were generated.
     *
     * @return void
     */
    public static function assertNothingGenerated(): void
    {
        Ai::manager()->assertNoEmbeddingsGenerated();
    }

    /**
     * Assert that a queued embeddings generation was recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertQueued(Closure $callback): void
    {
        Ai::manager()->assertEmbeddingsQueued($callback);
    }

    /**
     * Assert that a queued embeddings generation was not recorded matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertNotQueued(Closure $callback): void
    {
        Ai::manager()->assertEmbeddingsNotQueued($callback);
    }

    /**
     * Assert that no queued embeddings generations were recorded.
     *
     * @return void
     */
    public static function assertNothingQueued(): void
    {
        Ai::manager()->assertNoEmbeddingsQueued();
    }

    /**
     * Determine if embeddings generation is faked.
     *
     * @return bool
     */
    public static function isFaked(): bool
    {
        return Ai::manager()->embeddingsAreFaked();
    }

    /**
     * Generate a fake embedding vector of the given dimensions.
     *
     * @param int $dimensions Vector dimensions
     * @return array<int, float>
     */
    public static function fakeEmbedding(int $dimensions): array
    {
        $values = array_map(
            fn(): float|int => mt_rand() / mt_getrandmax() * 2 - 1,
            range(1, $dimensions),
        );

        $magnitude = sqrt(array_sum(array_map(fn($v): int|float => $v * $v, $values)));

        return array_map(fn($v): float => $v / $magnitude, $values);
    }
}
