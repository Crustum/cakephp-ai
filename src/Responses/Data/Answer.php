<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;

/**
 * Answer
 *
 * Base value object for a single classification answer.
 */
abstract class Answer implements JsonSerializable
{
    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed> The array representation.
     */
    abstract public function toArray(): array;

    /**
     * Get the JSON serializable representation of the instance.
     *
     * @return array<string, mixed> The JSON serializable data.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
