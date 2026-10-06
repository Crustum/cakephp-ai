<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Responses\RerankingResponse;

/**
 * Reranking Provider Interface
 *
 * Defines contract for providers that support document reranking capabilities.
 */
interface RerankingProvider extends Provider
{
    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param array<int, string> $documents Documents to rerank
     * @param string $query Query to rank documents against
     * @param int|null $limit Maximum number of results to return
     * @param string|null $model Model to use for reranking
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\RerankingResponse
     */
    public function rerank(
        array $documents,
        string $query,
        ?int $limit = null,
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): RerankingResponse;

    /**
     * Get the provider's reranking gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\RerankingGateway
     */
    public function rerankingGateway(): RerankingGateway;

    /**
     * Set the provider's reranking gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\RerankingGateway $gateway Reranking gateway
     * @return $this
     */
    public function useRerankingGateway(RerankingGateway $gateway);

    /**
     * Get the name of the default reranking model.
     *
     * @return string
     */
    public function defaultRerankingModel(): string;
}
