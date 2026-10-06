<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Closure;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Prompts\EmbeddingsPrompt;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use RuntimeException;

/**
 * Fake Embedding Gateway
 *
 * Fake implementation of embedding gateway for testing purposes.
 * Allows simulating embedding generation without making actual API calls.
 */
class FakeEmbeddingGateway implements EmbeddingGateway
{
    /**
     * Current response index for array responses
     */
    protected int $currentResponseIndex = 0;

    /**
     * Whether to prevent generations without fake responses
     */
    protected bool $preventStrayGenerations = false;

    /**
     * Constructor.
     *
     * @param \Closure|array $responses Responses to return
     */
    public function __construct(protected Closure|array $responses = [])
    {
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider The embedding provider instance
     * @param string $model The model to use for embedding generation
     * @param array<int, string|\Crustum\Ai\Files\Audio|\Crustum\Ai\Files\Document|\Crustum\Ai\Files\Image|\Crustum\Ai\Files\Video> $inputs Array of inputs to embed
     * @param int $dimensions The number of dimensions for the embedding vectors
     * @param int $timeout Timeout in seconds (default: 30)
     * @param array<string, mixed> $providerOptions Additional provider-specific options
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
        $prompt = new EmbeddingsPrompt($inputs, $dimensions, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $prompt);
    }

    /**
     * Get the next response instance.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\EmbeddingsPrompt $prompt The embeddings prompt
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     */
    protected function nextResponse(
        EmbeddingProvider $provider,
        string $model,
        EmbeddingsPrompt $prompt,
    ): EmbeddingsResponse {
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
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\EmbeddingsPrompt $prompt The embeddings prompt
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     */
    protected function marshalResponse(
        mixed $response,
        EmbeddingProvider $provider,
        string $model,
        EmbeddingsPrompt $prompt,
    ): EmbeddingsResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted embedding generation without a fake response.');
            }

            $response = $this->generateFakeEmbeddings(count($prompt->inputs), $prompt->dimensions);
        }

        if (is_array($response) && isset($response[0]) && is_array($response[0])) {
            return new EmbeddingsResponse(
                $response,
                new Usage(),
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Generate fake embedding vectors.
     *
     * @param int $count Number of embeddings to generate
     * @param int $dimensions Number of dimensions per embedding
     * @return array<int, array<float>>
     */
    protected function generateFakeEmbeddings(int $count, int $dimensions): array
    {
        if ($dimensions <= 0) {
            throw new RuntimeException('Unable to generate fake embeddings without positive dimensions. Configure embedding dimensions or provide a fake response.');
        }

        $embeddings = [];
        for ($i = 0; $i < $count; $i++) {
            $embedding = [];
            for ($j = 0; $j < $dimensions; $j++) {
                $embedding[] = (mt_rand(0, 1000) - 500) / 1000.0;
            }

            $embeddings[] = $embedding;
        }

        return $embeddings;
    }

    /**
     * Indicate that an exception should be thrown if any embeddings generation is not faked.
     *
     * @param bool $prevent Whether to prevent stray generations
     */
    public function preventStrayEmbeddings(bool $prevent = true): static
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
