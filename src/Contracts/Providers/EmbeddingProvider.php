<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Responses\EmbeddingsResponse;

/**
 * Embedding Provider Interface
 *
 * Defines contract for providers that support embedding generation capabilities.
 */
interface EmbeddingProvider extends Provider
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param array<int, string|\Crustum\Ai\Files\Audio|\Crustum\Ai\Files\Document|\Crustum\Ai\Files\Image|\Crustum\Ai\Files\Video> $inputs Inputs to embed
     * @param int|null $dimensions Number of dimensions for embeddings
     * @param string|null $model Model to use for embeddings
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     */
    public function embeddings(
        array $inputs,
        ?int $dimensions = null,
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse;

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway;

    /**
     * Set the provider's embedding gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\EmbeddingGateway $gateway Embedding gateway
     * @return $this
     */
    public function useEmbeddingGateway(EmbeddingGateway $gateway);

    /**
     * Get the name of the default embeddings model.
     *
     * @return string
     */
    public function defaultEmbeddingsModel(): string;

    /**
     * Get the default dimensions of the default embeddings model.
     *
     * @return int
     */
    public function defaultEmbeddingsDimensions(): int;
}
