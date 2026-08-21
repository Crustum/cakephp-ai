<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Closure;

/**
 * Tracks the run / tool invocation a nested run was delegated from.
 */
class ParentInvocation
{
    /**
     * The invocation and tool invocation the current run was delegated from.
     *
     * @var array{string|null, string|null}
     */
    protected static array $current = [null, null];

    /**
     * Get the invocation and tool invocation the current run was delegated from.
     *
     * @return array{string|null, string|null}
     */
    public static function current(): array
    {
        return static::$current;
    }

    /**
     * Run the given callback with the given invocation and tool invocation as the delegating parent.
     *
     * @param string|null $invocationId Parent run invocation identifier
     * @param string $toolInvocationId Parent tool invocation identifier
     * @param \Closure $callback Callback to run
     */
    public static function within(?string $invocationId, string $toolInvocationId, Closure $callback): mixed
    {
        $previous = static::$current;

        static::$current = [$invocationId, $toolInvocationId];

        try {
            return $callback();
        } finally {
            static::$current = $previous;
        }
    }
}
