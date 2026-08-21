<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Collection\CollectionInterface;
use Countable;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\RankedDocument;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Reranking Response
 *
 * Represents a response containing reranked documents.
 *
 * @implements \IteratorAggregate<int, \Crustum\Ai\Responses\Data\RankedDocument>
 */
class RerankingResponse implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new reranking response instance.
     *
     * @param array<int, \Crustum\Ai\Responses\Data\RankedDocument> $results The reranked results
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata about the response
     */
    public function __construct(
        public readonly array $results,
        public readonly Meta $meta,
    ) {
    }

    /**
     * Get the top-ranked result.
     */
    public function first(): ?RankedDocument
    {
        return $this->results[0] ?? null;
    }

    /**
     * Get the documents in their reranked order.
     *
     * @return \Cake\Collection\CollectionInterface<int, string>
     */
    public function documents(): CollectionInterface
    {
        return collection(array_map(
            fn(RankedDocument $result): string => $result->document,
            $this->results,
        ));
    }

    /**
     * Get the number of results in the response.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->results);
    }

    /**
     * Get the results as a collection.
     *
     * @return \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\RankedDocument>
     */
    public function collect(): CollectionInterface
    {
        return collection($this->results);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'results' => $this->results,
            'meta' => $this->meta,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get an iterator for the results.
     *
     * @return \Traversable<int, \Crustum\Ai\Responses\Data\RankedDocument>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->results as $result) {
            yield $result;
        }
    }
}
