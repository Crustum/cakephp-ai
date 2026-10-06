<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Cake\Collection\Collection;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\RankedDocument;
use Crustum\Ai\Responses\Data\RerankingUsage;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\RerankingResponse;
use RuntimeException;

/**
 * Jina embeddings and reranking gateway.
 */
class JinaGateway implements EmbeddingGateway, RerankingGateway
{
    use HandlesFailoverErrorsTrait;
    use MergesHeadersTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $jinaHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $jinaHttpTimeout = 30;

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
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('/embeddings', array_merge(
                ['task' => 'retrieval.passage'],
                $providerOptions,
                [
                    'model' => $model,
                    'input' => array_map(fn(string $text): array => ['text' => $text], $inputs),
                    'dimensions' => $dimensions,
                ],
            )),
        );

        $data = $response->getJson() ?? [];

        $embeddings = (new Collection($data['data'] ?? []))->extract('embedding')->toList();

        return new EmbeddingsResponse(
            $embeddings,
            new Usage($data['usage']['total_tokens'] ?? 0),
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('/rerank', array_merge($providerOptions, array_filter([
                'model' => $model,
                'query' => $query,
                'documents' => $documents,
                'top_n' => $limit,
            ]))),
        );

        $data = $response->getJson() ?? [];

        $results = (new Collection($data['results'] ?? []))->map(fn(array $result): RankedDocument => new RankedDocument(
            index: $result['index'],
            document: $documents[$result['index']],
            score: $result['relevance_score'],
        ))->toList();

        return new RerankingResponse(
            $results,
            new RerankingUsage($data['usage']['total_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the Jina API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, int $timeout = 30): static
    {
        $this->jinaHttpProvider = $provider;
        $this->jinaHttpTimeout = $timeout;

        return $this;
    }

    /**
     * Send a POST request to the Jina API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body = []): HttpResponseInterface
    {
        $provider = $this->jinaHttpProvider;

        if (!$provider instanceof Provider) {
            throw new RuntimeException('Jina HTTP provider is not configured.');
        }

        $config = $provider->additionalConfiguration();

        $url = rtrim((string)($config['url'] ?? 'https://api.jina.ai/v1'), '/') . '/' . ltrim($path, '/');

        $key = $provider->providerCredentials()['key'] ?? null;

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($key !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $headers = $this->mergeConfiguredHeaders($headers, $provider->additionalConfiguration()['headers'] ?? []);

        $http = HttpClientFactory::create($this->jinaHttpTimeout);

        return $http->post($url, json_encode($body) ?: '{}', [
            'headers' => $headers,
        ]);
    }
}
