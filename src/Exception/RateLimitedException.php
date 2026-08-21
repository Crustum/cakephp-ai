<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

use Throwable;

/**
 * Exception thrown when rate limited by provider.
 *
 * Triggered when the application exceeds the rate limits imposed
 * by an AI provider. Supports failover to alternative providers.
 */
class RateLimitedException extends AiException implements FailoverableException
{
    /**
     * Create a new exception for a rate-limited provider.
     *
     * @param string $provider The provider name
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            'Application rate limited by AI provider [' . $provider . '].',
            $code,
            $previous,
        );
    }
}
