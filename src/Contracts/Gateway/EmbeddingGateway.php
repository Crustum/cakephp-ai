<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Gateway;

use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Responses\EmbeddingsResponse;

/**
 * Embedding Gateway Interface
 *
 * Defines methods for generating embedding vectors from text inputs.
 */
interface EmbeddingGateway
{
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
    ): EmbeddingsResponse;
}
