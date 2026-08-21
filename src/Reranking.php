<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Cake\Collection\CollectionInterface;
use Closure;
use Crustum\Ai\Gateway\FakeRerankingGateway;
use Crustum\Ai\PendingResponses\PendingReranking;

/**
 * Reranking facade.
 *
 * Static interface for AI document reranking operations.
 */
class Reranking
{
    /**
     * Create a new pending reranking for the given documents.
     *
     * @param \Cake\Collection\CollectionInterface<int, string>|array<int, string> $documents Documents to rerank
     * @return \Crustum\Ai\PendingResponses\PendingReranking
     * @throws \InvalidArgumentException if the given documents are not a list, are empty, are not strings, or contain only blank strings
     */
    public static function of(CollectionInterface|array $documents): PendingReranking
    {
        if ($documents instanceof CollectionInterface) {
            $documents = $documents->toList();
        }

        return new PendingReranking($documents);
    }

    /**
     * Fake reranking operations.
     *
     * @param \Closure|array<int, mixed> $responses Fake responses
     * @return \Crustum\Ai\Gateway\FakeRerankingGateway
     */
    public static function fake(Closure|array $responses = []): FakeRerankingGateway
    {
        return Ai::manager()->fakeReranking($responses);
    }

    /**
     * Assert that a reranking was performed matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertReranked(Closure $callback): void
    {
        Ai::manager()->assertReranked($callback);
    }

    /**
     * Assert that a reranking was not performed matching a given truth test.
     *
     * @param \Closure $callback Truth test callback
     * @return void
     */
    public static function assertNotReranked(Closure $callback): void
    {
        Ai::manager()->assertNotReranked($callback);
    }

    /**
     * Assert that no rerankings were performed.
     *
     * @return void
     */
    public static function assertNothingReranked(): void
    {
        Ai::manager()->assertNothingReranked();
    }

    /**
     * Determine if reranking is faked.
     *
     * @return bool
     */
    public static function isFaked(): bool
    {
        return Ai::manager()->rerankingIsFaked();
    }
}
