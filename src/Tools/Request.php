<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools;

use ArrayAccess;

/**
 * Tool Request
 *
 * Represents the request parameters passed to a tool invocation.
 * Provides array access to the tool arguments.
 */
class Request implements ArrayAccess
{
    /**
     * Constructor
     *
     * @param array<string, mixed> $arguments The tool invocation arguments.
     * @param string|null $toolCallId Stable provider tool-call ID.
     * @param string|null $toolInvocationId Tool invocation correlation ID.
     */
    public function __construct(
        protected array $arguments = [],
        protected ?string $toolCallId = null,
        protected ?string $toolInvocationId = null,
    ) {
    }

    /**
     * Get the stable provider tool-call ID, usable as an external idempotency key.
     *
     * @return string|null
     */
    public function toolCallId(): ?string
    {
        return $this->toolCallId;
    }

    /**
     * Get the ID correlating this execution with the tool invocation events dispatched around it.
     *
     * @return string|null
     */
    public function toolInvocationId(): ?string
    {
        return $this->toolInvocationId;
    }

    /**
     * Get a string argument value.
     *
     * @param string $key Argument key.
     * @param string|null $default Default value.
     * @return string
     */
    public function string(string $key, ?string $default = null): string
    {
        return (string)($this->arguments[$key] ?? $default ?? '');
    }

    /**
     * Get a boolean argument value.
     *
     * @param string $key Argument key.
     * @param bool $default Default value.
     * @return bool
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->arguments[$key] ?? $default;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get an integer argument value.
     *
     * @param string $key Argument key.
     * @param int|null $default Default value.
     * @return int
     */
    public function integer(string $key, ?int $default = null): int
    {
        return (int)($this->arguments[$key] ?? $default ?? 0);
    }

    /**
     * Get all arguments or a subset by keys.
     *
     * @param array-key|array<array-key, string>|null $keys Optional keys to filter.
     * @return array<string, mixed> The arguments array.
     */
    public function all(mixed $keys = null): array
    {
        if (is_null($keys)) {
            return $this->data();
        }

        return array_intersect_key(
            $this->data(),
            array_flip(is_array($keys) ? $keys : func_get_args()),
        );
    }

    /**
     * Get the data for the request.
     *
     * @param mixed $key Optional key to retrieve specific value.
     * @param mixed $default Default value if key not found.
     * @return mixed The data or specific value.
     */
    protected function data(mixed $key = null, mixed $default = null): mixed
    {
        return is_null($key)
            ? $this->arguments
            : ($this->arguments[$key] ?? $default);
    }

    /**
     * Get the arguments as an array.
     *
     * @return array<string, mixed> The arguments array.
     */
    public function toArray(): array
    {
        return $this->arguments;
    }

    /**
     * Determine if an item exists at an offset.
     *
     * @param mixed $offset The offset to check.
     * @return bool True if offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->arguments[$offset]);
    }

    /**
     * Get an item at a given offset.
     *
     * @param mixed $offset The offset to get.
     * @return mixed The value at offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->arguments[$offset];
    }

    /**
     * Set the item at a given offset.
     *
     * @param mixed $offset The offset to set.
     * @param mixed $value The value to set.
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->arguments[] = $value;
        } else {
            $this->arguments[$offset] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     *
     * @param mixed $offset The offset to unset.
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->arguments[$offset]);
    }
}
