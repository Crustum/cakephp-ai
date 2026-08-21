<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;
use Stringable;

/**
 * Ranked Document
 *
 * Represents a document with a relevance score from a reranking operation.
 */
class RankedDocument implements JsonSerializable, Stringable
{
    /**
     * Constructor
     *
     * @param int $index The index of the document in the original list.
     * @param string $document The document content.
     * @param float $score The relevance score assigned by the reranker.
     */
    public function __construct(
        public readonly int $index,
        public readonly string $document,
        public readonly float $score,
    ) {
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed> The array representation.
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'document' => $this->document,
            'score' => $this->score,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     *
     * @return array<string, mixed> The JSON serializable data.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get the document content.
     *
     * @return string The document text.
     */
    public function __toString(): string
    {
        return $this->document;
    }
}
