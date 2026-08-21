<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Closure;

/**
 * Trait for conditional execution.
 *
 * Provides when/unless methods for fluent conditional logic.
 */
trait ConditionableTrait
{
    /**
     * Apply the callback if the given condition is true.
     *
     * @param \Closure|bool $condition Condition or closure that returns bool
     * @param callable $callback Callback to execute if condition is true
     * @param callable|null $default Callback to execute if condition is false
     * @return $this
     * @phpstan-return static
     */
    public function when(bool|Closure $condition, callable $callback, ?callable $default = null)
    {
        $condition = $condition instanceof Closure ? $condition($this) : $condition;
        if ($condition) {
            return $callback($this) ?? $this;
        }

        if ($default !== null) {
            return $default($this) ?? $this;
        }

        return $this;
    }

    /**
     * Apply the callback if the given condition is false.
     *
     * @param \Closure|bool $condition Condition or closure that returns bool
     * @param callable $callback Callback to execute if condition is false
     * @param callable|null $default Callback to execute if condition is true
     * @return $this
     * @phpstan-return static
     */
    public function unless(bool|Closure $condition, callable $callback, ?callable $default = null)
    {
        $condition = $condition instanceof Closure ? $condition($this) : $condition;
        if (!$condition) {
            return $callback($this) ?? $this;
        }

        if ($default !== null) {
            return $default($this) ?? $this;
        }

        return $this;
    }
}
