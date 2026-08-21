<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Trait;

/**
 * Trait for responses with structured data.
 *
 * Implements ArrayAccess to allow array-like access to structured output.
 */
trait ProvidesStructuredResponseTrait
{
    /**
     * Structured data.
     *
     * @var array<string, mixed>
     */
    public array $structured;

    /**
     * Determine if an item exists at an offset.
     *
     * @param mixed $offset Array key
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->structured[$offset]);
    }

    /**
     * Get an item at a given offset.
     *
     * @param mixed $offset Array key
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->structured[$offset];
    }

    /**
     * Set the item at a given offset.
     *
     * @param mixed $offset Array key
     * @param mixed $value Value to set
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->structured[] = $value;
        } else {
            $this->structured[$offset] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     *
     * @param mixed $offset Array key
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->structured[$offset]);
    }
}
