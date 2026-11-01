<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use Cake\Http\Client\Exception\ClientException;
use Cake\Http\Client\Exception\NetworkException;
use Cake\Utility\Hash;
use Closure;
use Crustum\Ai\Exception\InsufficientCreditsException;
use Crustum\Ai\Exception\ProviderConnectionException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use GuzzleHttp\Exception\ConnectException;

/**
 * Handles Failover Errors Trait
 *
 * Maps HTTP client errors to failoverable AI exceptions.
 */
trait HandlesFailoverErrorsTrait
{
    /**
     * Execute a callback with failoverable error handling.
     *
     * @template T
     * @param string $providerName Provider identifier
     * @param \Closure(): T $callback Operation to execute
     * @return T
     */
    protected function withErrorHandling(string $providerName, Closure $callback): mixed
    {
        $result = $this->executeCallback($providerName, $callback);

        if ($result instanceof HttpResponseInterface && !$result->isOk()) {
            $retryAfter = $this->retryAfterSeconds($result);

            if ($retryAfter !== null) {
                $this->sleepAfter($retryAfter);

                $result = $this->executeCallback($providerName, $callback);
            }

            if ($result instanceof HttpResponseInterface && !$result->isOk()) {
                $this->throwForFailoverResponse($providerName, $result);
            }
        }

        return $result;
    }

    /**
     * Execute the callback, mapping connection failures to a failoverable exception.
     *
     * @template T
     * @param string $providerName Provider identifier
     * @param \Closure(): T $callback Operation to execute
     * @return T
     */
    protected function executeCallback(string $providerName, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (NetworkException | ConnectException $networkException) {
            throw ProviderConnectionException::forProvider(
                $providerName,
                $networkException->getCode(),
                $networkException,
            );
        }
    }

    /**
     * Get the short Retry-After delay of a rate limited or overloaded response, if it has one.
     *
     * @param \Crustum\Ai\Http\Contract\HttpResponseInterface $response HTTP response
     * @return int|null
     */
    protected function retryAfterSeconds(HttpResponseInterface $response): ?int
    {
        $status = $response->getStatusCode();

        if ($status !== 429 && !in_array($status, $this->overloadedStatusCodes(), true)) {
            return null;
        }

        if ($this->isInsufficientCredits($response)) {
            return null;
        }

        $retryAfter = $response->getHeaderLine('Retry-After');

        return ctype_digit($retryAfter) && (int)$retryAfter <= 10 ? (int)$retryAfter : null;
    }

    /**
     * Sleep for the given number of seconds before retrying the request.
     *
     * @param int $seconds Seconds to sleep
     * @return void
     */
    protected function sleepAfter(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /**
     * Map a non-success HTTP HttpResponseInterface to a failoverable exception.
     *
     * @param string $providerName Provider identifier
     * @param \Crustum\Ai\Http\Contract\HttpResponseInterface $response HTTP HttpResponseInterface
     * @return never
     */
    protected function throwForFailoverResponse(string $providerName, HttpResponseInterface $response): never
    {
        $status = $response->getStatusCode();

        if ($this->isInsufficientCredits($response)) {
            throw InsufficientCreditsException::forProvider($providerName, $status);
        }

        if ($status === 429) {
            throw RateLimitedException::forProvider($providerName, $status);
        }

        if (in_array($status, $this->overloadedStatusCodes(), true)) {
            throw ProviderOverloadedException::forProvider($providerName, $status);
        }

        $patterns = $this->insufficientCreditPatterns();
        if ($patterns) {
            $json = $response->getJson();
            $message = Hash::get(is_array($json) ? $json : [], 'error.message');

            if (is_string($message)) {
                $message = strtolower($message);

                foreach ($patterns as $pattern) {
                    if (str_contains($message, (string)$pattern)) {
                        throw InsufficientCreditsException::forProvider($providerName, $status);
                    }
                }
            }
        }

        throw new ClientException('HTTP request returned status code ' . $status, $status);
    }

    /**
     * Determine if the response is a 402, or a 429 insufficient credits or quota error rather than a rate limit.
     */
    protected function isInsufficientCredits(HttpResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        if ($status === 402) {
            return true;
        }

        if ($status !== 429) {
            return false;
        }

        $codes = $this->insufficientCreditCodes();

        return in_array(Hash::get($response->getJson() ?? [], 'error.code'), $codes, true)
            || in_array(Hash::get($response->getJson() ?? [], 'error.type'), $codes, true);
    }

    /**
     * The status codes that indicate a provider is transiently unavailable and the request should fail over.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return [502, 503, 504, 520, 522, 524];
    }

    /**
     * The patterns used to detect insufficient credits or quota errors.
     *
     * @return list<string>
     */
    protected function insufficientCreditPatterns(): array
    {
        return [];
    }

    /**
     * The error codes or types that indicate insufficient credits or quota on a 429 response.
     *
     * @return list<string>
     */
    protected function insufficientCreditCodes(): array
    {
        // OpenAI sends these with a 429 status, and may still use "insufficient_quota" as the error type.
        return [
            'insufficient_quota',
            'credit_balance_exhausted',
            'organization_spend_limit_exceeded',
            'project_spend_limit_exceeded',
            'organization_usage_limit_exceeded',
        ];
    }
}
