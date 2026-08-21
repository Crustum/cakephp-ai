<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Cake\Collection\Collection;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Gateway\Cohere\Trait\ParsesEmbeddingsTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\RankedDocument;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\RerankingResponse;
use RuntimeException;

/**
 * Cohere embeddings and reranking gateway.
 */
class CohereGateway implements EmbeddingGateway, RerankingGateway
{
    use HandlesFailoverErrorsTrait;
    use MergesHeadersTrait;
    use ParsesEmbeddingsTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $cohereHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $cohereHttpTimeout = 30;

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider Embedding provider
     * @param string $model Model name
     * @param array<int, string> $inputs Inputs to embed
     * @param int $dimensions Embedding dimensions
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('/embed', array_merge(
                [
                    'input_type' => 'search_document',
                    'embedding_types' => ['float'],
                ],
                $providerOptions,
                [
                    'model' => $model,
                    'texts' => $inputs,
                ],
            )),
        );

        $data = $response->getJson() ?? [];

        return new EmbeddingsResponse(
            $this->parseCohereEmbeddings($data['embeddings'] ?? []),
            $data['meta']['billed_units']['input_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param \Crustum\Ai\Contracts\Providers\RerankingProvider $provider Reranking provider
     * @param string $model Model name
     * @param array<int, string> $documents Documents to rerank
     * @param string $query Query to use for relevance scoring
     * @param int|null $limit Maximum number of results
     * @return \Crustum\Ai\Responses\RerankingResponse
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null,
    ): RerankingResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)->post('/rerank', array_filter([
                'model' => $model,
                'query' => $query,
                'documents' => $documents,
                'top_n' => $limit,
            ])),
        );

        $data = $response->getJson() ?? [];

        $results = (new Collection($data['results'] ?? []))->map(fn(array $result): RankedDocument => new RankedDocument(
            index: $result['index'],
            document: $documents[$result['index']],
            score: $result['relevance_score'],
        ))->toList();

        return new RerankingResponse(
            $results,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the Cohere API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, int $timeout = 30): static
    {
        $this->cohereHttpProvider = $provider;
        $this->cohereHttpTimeout = $timeout;

        return $this;
    }

    /**
     * Send a POST request to the Cohere API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body = []): HttpResponseInterface
    {
        $provider = $this->cohereHttpProvider;

        if (!$provider instanceof Provider) {
            throw new RuntimeException('Cohere HTTP provider is not configured.');
        }

        $config = $provider->additionalConfiguration();

        $url = rtrim((string)($config['url'] ?? 'https://api.cohere.com/v2'), '/') . '/' . ltrim($path, '/');

        $key = $provider->providerCredentials()['key'] ?? null;

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($key !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $headers = $this->mergeConfiguredHeaders($headers, $config['headers'] ?? []);

        $http = HttpClientFactory::create($this->cohereHttpTimeout);

        return $http->post($url, json_encode($body) ?: '{}', [
            'headers' => $headers,
        ]);
    }
}
