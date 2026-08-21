<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Countable;
use Crustum\Ai\Responses\Data\Meta;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Embeddings Response
 *
 * Represents a response containing text embeddings.
 *
 * @implements \IteratorAggregate<int, array<float>>
 */
class EmbeddingsResponse implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new embeddings response instance.
     *
     * @param array<int, array<float>> $embeddings The generated embeddings
     * @param int $tokens Number of tokens used
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata about the response
     */
    public function __construct(public array $embeddings, public int $tokens, public Meta $meta)
    {
    }

    /**
     * Get the first set of embeddings in the response.
     *
     * @return array<float>
     */
    public function first(): array
    {
        return $this->embeddings[0];
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'embeddings' => $this->embeddings,
            'tokens' => $this->tokens,
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
     * Get the number of generated embeddings in the response.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->embeddings);
    }

    /**
     * Get an iterator for the object.
     *
     * @return \Traversable<int, array<float>>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->embeddings as $embedding) {
            yield $embedding;
        }
    }
}
