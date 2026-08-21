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
        try {
            $result = $callback();
        } catch (NetworkException | ConnectException $networkException) {
            throw ProviderConnectionException::forProvider(
                $providerName,
                $networkException->getCode(),
                $networkException,
            );
        }

        if ($result instanceof HttpResponseInterface && !$result->isOk()) {
            $this->throwForFailoverResponse($providerName, $result);
        }

        return $result;
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

        if ($status === 429) {
            throw RateLimitedException::forProvider($providerName, $status);
        }

        if ($status === 402) {
            throw InsufficientCreditsException::forProvider($providerName, $status);
        }

        if (in_array($status, $this->overloadedStatusCodes(), true)) {
            throw ProviderOverloadedException::forProvider($providerName, $status);
        }

        $patterns = $this->insufficientCreditPatterns();
        if ($patterns) {
            $json = $response->getJson();
            $message = strtolower((string)Hash::get(is_array($json) ? $json : [], 'error.message', ''));

            foreach ($patterns as $pattern) {
                if (str_contains($message, (string)$pattern)) {
                    throw InsufficientCreditsException::forProvider($providerName, $status);
                }
            }
        }

        throw new ClientException('HTTP request returned status code ' . $status, $status);
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
}
