<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Cake\Collection\Collection;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\Cohere\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\Cohere\Trait\HandlesTextStreamingTrait;
use Crustum\Ai\Gateway\Cohere\Trait\ParsesEmbeddingsTrait;
use Crustum\Ai\Gateway\Cohere\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionMessagesTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionToolsTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Gateway\Trait\ParsesServerSentEventsTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\RankedDocument;
use Crustum\Ai\Responses\Data\RerankingUsage;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\RerankingResponse;
use Crustum\Ai\Utility\Value;
use Generator;
use RuntimeException;

/**
 * Cohere embeddings, reranking, and text gateway.
 */
class CohereGateway implements EmbeddingGateway, RerankingGateway, StepTextGateway
{
    use BuildsTextRequestsTrait;
    use HandlesFailoverErrorsTrait;
    use HandlesTextStreamingTrait;
    use MapsAttachmentsTrait;
    use MapsChatCompletionMessagesTrait;
    use MapsChatCompletionToolsTrait;
    use MergesHeadersTrait;
    use ParsesEmbeddingsTrait;
    use ParsesServerSentEventsTrait;
    use ParsesTextResponsesTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $cohereHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $cohereHttpTimeout = 30;

    /**
     * Whether the pending HTTP request should stream.
     */
    protected bool $cohereHttpStream = false;

    /**
     * Generate text for a single step in a conversation.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     * @param string $model Model name
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param int|null $timeout Timeout in seconds
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Step context
     * @return \Crustum\Ai\Gateway\StepResponse
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $body = $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout ?? 60)->post('/chat', $body),
        );

        $data = $response->getJson() ?? [];

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, $model, Value::filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single step in a conversation.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     * @param string $model Model name
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param int|null $timeout Timeout in seconds
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Step context
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent, mixed, \Crustum\Ai\Gateway\StepResponse|null>
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $body = $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $body['stream'] = true;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout ?? 60)
                ->withOptions(['stream' => true])
                ->post('/chat', $body),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

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
            new Usage($data['meta']['billed_units']['input_tokens'] ?? 0),
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
            new RerankingUsage(
                inputTokens: $data['meta']['billed_units']['input_tokens'] ?? 0,
                searchUnits: $data['meta']['billed_units']['search_units'] ?? null,
            ),
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
        $this->cohereHttpStream = false;

        return $this;
    }

    /**
     * Set HTTP client options for the pending request.
     *
     * @param array<string, mixed> $options HTTP options
     */
    protected function withOptions(array $options): static
    {
        $this->cohereHttpStream = (bool)($options['stream'] ?? false);

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
            'Accept' => $this->cohereHttpStream ? 'text/event-stream' : 'application/json',
        ];

        if ($key !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $headers = $this->mergeConfiguredHeaders($headers, $config['headers'] ?? []);

        $http = HttpClientFactory::create($this->cohereHttpTimeout);

        $options = [
            'headers' => $headers,
        ];

        if ($this->cohereHttpStream) {
            $options['stream'] = true;
        }

        return $http->post($url, json_encode($body) ?: '{}', $options);
    }
}
