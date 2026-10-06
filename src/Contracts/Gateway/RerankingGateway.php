<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Gateway;

use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Responses\RerankingResponse;

/**
 * Reranking Gateway Interface
 *
 * Defines methods for reranking documents based on relevance to a query.
 */
interface RerankingGateway
{
    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param \Crustum\Ai\Contracts\Providers\RerankingProvider $provider The reranking provider instance
     * @param string $model The model to use for reranking
     * @param array<int, string> $documents Array of documents to rerank
     * @param string $query The query to use for relevance scoring
     * @param int|null $limit Maximum number of results to return
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\RerankingResponse
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): RerankingResponse;
}
