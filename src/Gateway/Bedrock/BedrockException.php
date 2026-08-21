<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Bedrock;

use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Exception\InsufficientCreditsException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Throwable;

/**
 * Bedrock exception mapper.
 *
 * Maps AWS Bedrock runtime exceptions to failoverable AI exceptions.
 */
class BedrockException
{
    /**
     * Patterns that indicate an insufficient credits or quota error.
     *
     * @var list<string>
     */
    protected static array $insufficientCreditPatterns = [
        'credit balance',
        'insufficient',
        'quota exceeded',
        'exceeded your current quota',
        'billing',
        'service quota',
    ];

    /**
     * Create a new AI exception from an AWS Bedrock exception.
     *
     * @param \Throwable $e The original exception
     * @param string $provider Provider name
     * @param string $model Model name
     * @return \Crustum\Ai\Exception\AiException
     */
    public static function toAiException(Throwable $e, string $provider, string $model): AiException
    {
        if ($e instanceof BedrockRuntimeException) {
            return match ($e->getAwsErrorCode()) {
                'ThrottlingException' => RateLimitedException::forProvider($provider, $e->getStatusCode(), $e),
                'ServiceUnavailableException',
                'ModelNotReadyException',
                'ModelTimeoutException',
                'ModelStreamErrorException',
                'InternalServerException' => new ProviderOverloadedException(
                    'AI provider [' . $provider . '] is overloaded or unavailable.',
                    $e->getStatusCode(),
                    $e,
                ),
                'ServiceQuotaExceededException' => InsufficientCreditsException::forProvider($provider, $e->getStatusCode(), $e),
                default => new AiException(
                    'AWS Bedrock error for provider [' . $provider . ']: ' . $e->getMessage(),
                    $e->getCode(),
                    $e,
                ),
            };
        }

        if (static::isInsufficientCreditsError($e)) {
            return InsufficientCreditsException::forProvider($provider, $e->getCode(), $e);
        }

        return new AiException(
            $e->getMessage(),
            $e->getCode(),
            $e,
        );
    }

    /**
     * Determine if the given exception indicates an insufficient credits or quota error.
     *
     * @param \Throwable $e The exception
     * @return bool
     */
    protected static function isInsufficientCreditsError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (static::$insufficientCreditPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
