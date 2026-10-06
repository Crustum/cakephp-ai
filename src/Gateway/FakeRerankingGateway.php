<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Closure;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Prompts\RerankingPrompt;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\RankedDocument;
use Crustum\Ai\Responses\Data\RerankingUsage;
use Crustum\Ai\Responses\RerankingResponse;
use RuntimeException;

/**
 * Fake Reranking Gateway
 *
 * Fake implementation of reranking gateway for testing purposes.
 * Allows simulating reranking operations without making actual API calls.
 */
class FakeRerankingGateway implements RerankingGateway
{
    /**
     * Current response index for array responses
     */
    protected int $currentResponseIndex = 0;

    /**
     * Whether to prevent rerankings without fake responses
     */
    protected bool $preventStrayRerankings = false;

    /**
     * Constructor.
     *
     * @param \Closure|array $responses Responses to return
     */
    public function __construct(protected Closure|array $responses = [])
    {
    }

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
        $prompt = new RerankingPrompt($documents, $query, $limit, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $prompt);
    }

    /**
     * Get the next response instance.
     *
     * @param \Crustum\Ai\Contracts\Providers\RerankingProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\RerankingPrompt $prompt The reranking prompt
     * @return \Crustum\Ai\Responses\RerankingResponse
     */
    protected function nextResponse(
        RerankingProvider $provider,
        string $model,
        RerankingPrompt $prompt,
    ): RerankingResponse {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt);

        $result = $this->marshalResponse($response, $provider, $model, $prompt);
        $this->currentResponseIndex++;

        return $result;
    }

    /**
     * Marshal the given response into a full response instance.
     *
     * @param mixed $response The response to marshal
     * @param \Crustum\Ai\Contracts\Providers\RerankingProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\RerankingPrompt $prompt The reranking prompt
     * @return \Crustum\Ai\Responses\RerankingResponse
     */
    protected function marshalResponse(
        mixed $response,
        RerankingProvider $provider,
        string $model,
        RerankingPrompt $prompt,
    ): RerankingResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayRerankings) {
                throw new RuntimeException('Attempted reranking without a fake response.');
            }

            $response = $this->generateFakeRanking($prompt->documents, $prompt->limit);
        }

        if ($response instanceof RerankingResponse) {
            return $response;
        }

        if (is_array($response) && isset($response[0]) && $response[0] instanceof RankedDocument) {
            return new RerankingResponse(
                $response,
                new RerankingUsage(),
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Generate a fake ranking for the given documents.
     *
     * @param array<int, string> $documents The documents to rank
     * @param int|null $limit Maximum number of results
     * @return array<int, \Crustum\Ai\Responses\Data\RankedDocument>
     */
    protected function generateFakeRanking(array $documents, ?int $limit = null): array
    {
        $indices = array_keys($documents);
        shuffle($indices);

        $indices = array_slice($indices, 0, $limit ?? count($documents));

        $results = [];

        foreach ($indices as $position => $index) {
            $results[] = new RankedDocument(
                index: $index,
                document: $documents[$index],
                score: 1.0 - ($position * 1.0 / count($indices)),
            );
        }

        return $results;
    }

    /**
     * Indicate that an exception should be thrown if any reranking is not faked.
     *
     * @param bool $prevent Whether to prevent stray rerankings
     */
    public function preventStrayRerankings(bool $prevent = true): static
    {
        $this->preventStrayRerankings = $prevent;

        return $this;
    }
}
