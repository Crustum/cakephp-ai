<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

use Throwable;

/**
 * Exception thrown when provider has insufficient credits or quota.
 *
 * Triggered when an AI provider account runs out of credits or
 * exceeds quota limits. Supports failover to alternative providers.
 */
class InsufficientCreditsException extends AiException implements FailoverableException
{
    /**
     * Create a new exception for a provider with insufficient credits.
     *
     * @param string $provider The provider name
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            'AI provider [' . $provider . '] has insufficient credits or quota.',
            $code,
            $previous,
        );
    }
}
