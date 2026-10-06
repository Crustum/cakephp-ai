<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

/**
 * Reranking Usage
 *
 * Tracks token usage for reranking requests.
 */
readonly class RerankingUsage extends Usage
{
    /**
     * Constructor
     *
     * @param int $inputTokens Total tokens across the query and the documents.
     * @param float|null $searchUnits Billed search units, or null when unreported.
     */
    public function __construct(
        int $inputTokens = 0,
        public ?float $searchUnits = null,
    ) {
        parent::__construct($inputTokens);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, int|float|null> The array representation.
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'search_units' => $this->searchUnits,
        ];
    }
}
