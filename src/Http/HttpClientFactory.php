<?php
declare(strict_types=1);

namespace Crustum\Ai\Http;

use Crustum\Ai\Http\Contract\HttpClientAdapterInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * Central factory for AI provider HTTP clients.
 *
 * This is the single place that names the active transport. A future swap
 * (e.g. another HTTP library) only requires changing `create()` here; the
 * ~15 gateway traits all delegate to this factory.
 *
 * In tests, `HttpCapture` injects a mock handler stack via `useTestHandler()`.
 */
final class HttpClientFactory
{
    /**
     * @var \GuzzleHttp\HandlerStack|null
     */
    private static ?HandlerStack $testHandler = null;

    /**
     * @var array<int, array{request: mixed, response: mixed, error: mixed, options: mixed}>
     */
    private static array $history = [];

    /**
     * Create an HTTP client adapter for the given timeout.
     *
     * @param int $timeout Request timeout in seconds
     * @param array<string, mixed> $config Extra Guzzle configuration
     * @return \Crustum\Ai\Http\Contract\HttpClientAdapterInterface
     */
    public static function create(int $timeout = 60, array $config = []): HttpClientAdapterInterface
    {
        return new GuzzleHttpClientAdapter($timeout, $config, self::$testHandler);
    }

    /**
     * Install a test handler stack (mock transport + history recording).
     *
     * @return void
     */
    public static function useTestHandler(HandlerStack $stack): void
    {
        $stack->push(Middleware::history(self::$history));
        self::$testHandler = $stack;
    }

    /**
     * Clear the test handler and recorded history.
     *
     * @return void
     */
    public static function resetTestHandler(): void
    {
        self::$testHandler = null;
        self::$history = [];
    }

    /**
     * Get the recorded request history.
     *
     * @return array<int, array{request: mixed, response: mixed, error: mixed, options: mixed}>
     */
    public static function history(): array
    {
        return self::$history;
    }
}
