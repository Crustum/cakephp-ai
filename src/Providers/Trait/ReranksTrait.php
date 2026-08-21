<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Ai;
use Crustum\Ai\Event\Reranked;
use Crustum\Ai\Event\Reranking;
use Crustum\Ai\Prompts\RerankingPrompt;
use Crustum\Ai\Responses\RerankingResponse;

/**
 * Reranks documents through the provider's reranking gateway.
 */
trait ReranksTrait
{
    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param array<int, string> $documents Documents to rerank
     * @param string $query Query string
     * @param int|null $limit Maximum results
     * @param string|null $model Model name
     * @return \Crustum\Ai\Responses\RerankingResponse
     */
    public function rerank(array $documents, string $query, ?int $limit = null, ?string $model = null): RerankingResponse
    {
        $invocationId = Text::uuid();

        $model ??= $this->defaultRerankingModel();

        $prompt = new RerankingPrompt($documents, $query, $limit, $this, $model);

        if (Ai::manager()->rerankingIsFaked()) {
            Ai::manager()->recordReranking($prompt);
        }

        $this->events->dispatch(new Reranking(
            $invocationId,
            $this,
            $model,
            $prompt,
        ));

        $response = $this->rerankingGateway()->rerank(
            $this,
            $model,
            $documents,
            $query,
            $limit,
        );

        $this->events->dispatch(new Reranked(
            $invocationId,
            $this,
            $model,
            $prompt,
            $response,
        ));

        return $response;
    }
}
