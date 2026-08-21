<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Countable;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;

/**
 * Embeddings Prompt Class
 *
 * Represents a prompt for generating embeddings from text inputs.
 */
class EmbeddingsPrompt implements Countable
{
    /**
     * Create a new embeddings prompt instance.
     *
     * @param array<int, string|\Crustum\Ai\Files\Audio|\Crustum\Ai\Files\Document|\Crustum\Ai\Files\Image|\Crustum\Ai\Files\Video> $inputs The inputs to embed
     * @param int $dimensions The number of dimensions for the embeddings
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider The embedding provider
     * @param string $model The model identifier
     * @param int $timeout The timeout in seconds
     * @param array<string, mixed> $providerOptions Additional provider-specific options
     */
    public function __construct(
        public readonly array $inputs,
        public readonly int $dimensions,
        public readonly EmbeddingProvider $provider,
        public readonly string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if any of the inputs contain the given string.
     *
     * @param string $string The string to search for
     * @return bool
     */
    public function contains(string $string): bool
    {
        return array_any($this->inputs, fn(mixed $input): bool => is_string($input) && str_contains($input, $string));
    }

    /**
     * Get the number of inputs in the prompt.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->inputs);
    }
}
