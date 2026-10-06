<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Countable;
use Crustum\Ai\Contracts\Providers\RerankingProvider;

/**
 * Reranking Prompt Class
 *
 * Represents a prompt for reranking documents based on a query.
 */
class RerankingPrompt implements Countable
{
    /**
     * Create a new reranking prompt instance.
     *
     * @param array<int, string> $documents The documents to rerank
     * @param string $query The search query
     * @param int|null $limit The maximum number of results to return
     * @param \Crustum\Ai\Contracts\Providers\RerankingProvider $provider The reranking provider
     * @param string $model The model identifier
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     */
    public function __construct(
        public readonly array $documents,
        public readonly string $query,
        public readonly ?int $limit,
        public readonly RerankingProvider $provider,
        public readonly string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if the query contains the given string.
     *
     * @param string $string The string to search for
     * @return bool
     */
    public function contains(string $string): bool
    {
        return str_contains($this->query, $string);
    }

    /**
     * Determine if any of the documents contain the given string.
     *
     * @param string $string The string to search for
     * @return bool
     */
    public function documentsContain(string $string): bool
    {
        return array_any($this->documents, fn(string $document): bool => str_contains($document, $string));
    }

    /**
     * Get the number of documents in the prompt.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->documents);
    }
}
