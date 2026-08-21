<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

use Throwable;

/**
 * Exception thrown when a connection to an AI provider cannot be established.
 *
 * Triggered when the underlying HTTP client cannot reach the provider's servers.
 * Supports failover to alternative providers.
 */
class ProviderConnectionException extends AiException implements FailoverableException
{
    /**
     * Create a new exception for a provider connection failure.
     *
     * @param string $provider The provider name
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            'Could not connect to AI provider [' . $provider . '].',
            $code,
            $previous,
        );
    }
}
