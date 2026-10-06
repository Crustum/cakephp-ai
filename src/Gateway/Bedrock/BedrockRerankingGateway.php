<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Bedrock;

use Cake\Collection\Collection;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Gateway\Bedrock\Trait\CreatesBedrockClientTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\RankedDocument;
use Crustum\Ai\Responses\Data\RerankingUsage;
use Crustum\Ai\Responses\RerankingResponse;
use Throwable;

/**
 * AWS Bedrock reranking gateway.
 *
 * Reranks documents using a Bedrock reranking model (Cohere or Amazon).
 */
class BedrockRerankingGateway implements RerankingGateway
{
    use CreatesBedrockClientTrait;
    use HandlesFailoverErrorsTrait;

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
    ): RerankingResponse {
        $client = $this->createBedrockClient($provider, $timeout);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn() => $client->invokeModel([
                    'modelId' => $model,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode(array_merge($providerOptions, array_filter([
                        'query' => $query,
                        'documents' => array_values($documents),
                        'top_n' => $limit,
                        'api_version' => str_starts_with($model, 'cohere.') ? 2 : null,
                    ]))),
                ]),
            );

            $data = json_decode((string)$response->get('body')->getContents(), true);
        } catch (Throwable $throwable) {
            throw BedrockException::toAiException($throwable, $provider->name(), $model);
        }

        $results = (new Collection($data['results'] ?? []))->map(
            fn(array $result): RankedDocument => new RankedDocument(
                (int)$result['index'],
                $documents[(int)$result['index']],
                (float)$result['relevance_score'],
            ),
        )->toList();

        return new RerankingResponse(
            $results,
            new RerankingUsage(),
            new Meta($provider->name(), $model),
        );
    }
}
