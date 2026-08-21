<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Cohere\Trait;

use Crustum\Ai\Exception\AiException;

/**
 * Normalizes Cohere embeddings responses into a list of vectors.
 */
trait ParsesEmbeddingsTrait
{
    /**
     * Normalize the embeddings of a Cohere response into a list of vectors.
     *
     * @return array<int, array<int, float>>
     * @throws \Crustum\Ai\Exception\AiException
     */
    protected function parseCohereEmbeddings(mixed $embeddings): array
    {
        if (!is_array($embeddings)) {
            return [];
        }

        if (!array_is_list($embeddings)) {
            return $embeddings['float'] ?? throw new AiException(sprintf(
                'Cohere returned [%s] embeddings, but only float embeddings are supported.',
                implode(', ', array_keys($embeddings)),
            ));
        }

        return $embeddings;
    }
}
