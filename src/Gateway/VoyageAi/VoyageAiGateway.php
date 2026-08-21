<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\VoyageAi;

use Cake\Collection\Collection;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\VoyageAi\Trait\CreatesVoyageAiClientTrait;
use Crustum\Ai\Gateway\VoyageAi\Trait\MapsEmbeddingInputsTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\RankedDocument;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\RerankingResponse;
use InvalidArgumentException;

/**
 * Voyage AI embeddings and reranking gateway.
 */
class VoyageAiGateway implements EmbeddingGateway, RerankingGateway
{
    use CreatesVoyageAiClientTrait;
    use HandlesFailoverErrorsTrait;
    use MapsEmbeddingInputsTrait;

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider Embedding provider
     * @param string $model Model name
     * @param array<int, mixed> $inputs Inputs to embed
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
        if ($this->usesMultimodalEmbeddingEndpoint($model, $inputs)) {
            return $this->generateMultimodalEmbeddings($provider, $model, $inputs, $dimensions, $timeout, $providerOptions);
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('/embeddings', array_merge($providerOptions, [
                'model' => $model,
                'input' => $inputs,
                'output_dimension' => $dimensions,
            ])),
        );

        $data = $response->getJson() ?? [];

        return new EmbeddingsResponse(
            (new Collection($data['data'] ?? []))->extract('embedding')->toList(),
            $data['usage']['total_tokens'] ?? 0,
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
                'top_k' => $limit,
            ])),
        );

        $data = $response->getJson() ?? [];

        $results = [];

        foreach ($data['data'] ?? [] as $result) {
            $results[] = new RankedDocument(
                index: $result['index'],
                document: $documents[$result['index']],
                score: $result['relevance_score'],
            );
        }

        return new RerankingResponse(
            $results,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate embeddings for text and image inputs using Voyage AI's multimodal endpoint.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider Embedding provider
     * @param string $model Model name
     * @param array<int, mixed> $inputs Inputs to embed
     * @param int $dimensions Embedding dimensions
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     */
    protected function generateMultimodalEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        if ($model === 'voyage-multimodal-3' && $dimensions !== 1024) {
            throw new InvalidArgumentException(
                'Model [voyage-multimodal-3] only supports 1024 dimension embeddings. Use [voyage-multimodal-3.5] for other dimensions.',
            );
        }

        $this->validateMultimodalEmbeddingInputSources($inputs);

        $mappedInputs = [];

        foreach ($inputs as $input) {
            $mappedInputs[] = [
                'content' => [$this->mapMultimodalEmbeddingInput($input)],
            ];
        }

        $body = array_merge($providerOptions, [
            'model' => $model,
            'inputs' => $mappedInputs,
        ]);

        if ($model !== 'voyage-multimodal-3') {
            $body['output_dimension'] = $dimensions;
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('/multimodalembeddings', $body),
        );

        $data = $response->getJson() ?? [];

        return new EmbeddingsResponse(
            (new Collection($data['data'] ?? []))->extract('embedding')->toList(),
            $data['usage']['total_tokens'] ?? $data['total_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }
}
