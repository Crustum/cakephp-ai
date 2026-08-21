<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

use Throwable;

/**
 * Exception thrown when provider is overloaded.
 *
 * Triggered when an AI provider's servers are experiencing high load
 * and cannot process requests. Supports failover to alternative providers.
 */
class ProviderOverloadedException extends AiException implements FailoverableException
{
    /**
     * Create a new exception for an overloaded provider.
     *
     * @param string $provider The provider name
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            'AI provider [' . $provider . '] is overloaded.',
            $code,
            $previous,
        );
    }
}
