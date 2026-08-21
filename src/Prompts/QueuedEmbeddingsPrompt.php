<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Countable;
use Crustum\Ai\Enums\Lab;

/**
 * Queued embeddings generation prompt.
 *
 * Represents an embeddings generation request that has been queued
 * for asynchronous processing.
 */
class QueuedEmbeddingsPrompt implements Countable
{
    /**
     * Constructor.
     *
     * @param array<int, string|\Crustum\Ai\Files\Audio|\Crustum\Ai\Files\Document|\Crustum\Ai\Files\Image|\Crustum\Ai\Files\Video> $inputs Inputs to embed
     * @param int|null $dimensions Embedding dimensions
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider specification
     * @param string|null $model Model identifier
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     */
    public function __construct(
        public readonly array $inputs,
        public readonly ?int $dimensions,
        public readonly Lab|array|string|null $provider,
        public readonly ?string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if any of the inputs contain the given string.
     *
     * @param string $string String to search for
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
