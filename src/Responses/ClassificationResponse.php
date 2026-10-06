<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use ArrayAccess;
use Cake\Collection\CollectionInterface;
use Countable;
use Crustum\Ai\Responses\Data\Answer;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

/**
 * Classification Response
 *
 * Represents a response containing answers keyed by question.
 *
 * @implements \IteratorAggregate<string, \Crustum\Ai\Responses\Data\Answer>
 * @implements \ArrayAccess<string, \Crustum\Ai\Responses\Data\Answer>
 */
class ClassificationResponse implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new classification response instance.
     *
     * @param array<string, \Crustum\Ai\Responses\Data\Answer> $answers Answers keyed by question.
     * @param \Crustum\Ai\Responses\Data\TextUsage $usage Token usage information.
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata about the response.
     */
    public function __construct(
        public readonly array $answers,
        public readonly TextUsage $usage,
        public readonly Meta $meta,
    ) {
    }

    /**
     * Get the answer for the given question key.
     *
     * @param string $key The question key.
     * @return \Crustum\Ai\Responses\Data\Answer The answer.
     * @throws \InvalidArgumentException If no answer exists for the key.
     */
    public function answer(string $key): Answer
    {
        return $this->answers[$key] ?? throw new InvalidArgumentException(
            "No answer was returned for question [{$key}].",
        );
    }

    /**
     * Get the answers as a collection.
     *
     * @return \Cake\Collection\CollectionInterface<string, \Crustum\Ai\Responses\Data\Answer>
     */
    public function collect(): CollectionInterface
    {
        return collection($this->answers);
    }

    /**
     * Get the number of answers in the response.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->answers);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answers' => $this->answers,
            'usage' => $this->usage,
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
     * Get an iterator for the answers.
     *
     * @return \Traversable<string, \Crustum\Ai\Responses\Data\Answer>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->answers as $key => $answer) {
            yield $key => $answer;
        }
    }

    /**
     * Determine if an answer exists for the given key.
     *
     * @param mixed $offset Array key.
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->answers[$offset]);
    }

    /**
     * Get the answer for the given key.
     *
     * @param mixed $offset Array key.
     * @return \Crustum\Ai\Responses\Data\Answer
     */
    public function offsetGet(mixed $offset): Answer
    {
        return $this->answer($offset);
    }

    /**
     * Answers are immutable.
     *
     * @param mixed $offset Array key.
     * @param mixed $value Value.
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Classification answers are read-only.');
    }

    /**
     * Answers are immutable.
     *
     * @param mixed $offset Array key.
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Classification answers are read-only.');
    }
}
