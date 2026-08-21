<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Capture;

use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Http\Response;
use Crustum\Ai\TestSuite\Http\HttpResponseDefinition;
use Crustum\Ai\TestSuite\Http\HttpResponseSequence;
use Crustum\Ai\TestSuite\Http\RecordedHttp;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;

/**
 * Captures and fakes provider HTTP traffic for tests.
 *
 * Backed by Guzzle's HandlerStack + history middleware (see HttpClientFactory),
 * replacing Cake\Http\Client's mock responses and request events. The public
 * fake/response/sequence API is unchanged.
 */
class HttpCapture
{
    /**
     * @var array<int, array{method: string|null, pattern: string, resolve: callable(): \Crustum\Ai\Http\Response}>
     */
    protected static array $mocks = [];

    /**
     * @var callable|null
     */
    protected static $callback;

    /**
     * @var callable|null
     */
    protected static $catchAll;

    /**
     * Create a fake HTTP response definition.
     *
     * @param array<string, mixed>|string $body Response body
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return \Crustum\Ai\TestSuite\Http\HttpResponseDefinition
     */
    public static function response(
        array|string $body = [],
        int $status = 200,
        array $headers = [],
    ): HttpResponseDefinition {
        return new HttpResponseDefinition($body, $status, $headers);
    }

    /**
     * Create a sequence of fake HTTP responses.
     *
     * @param array<int, \Crustum\Ai\TestSuite\Http\HttpResponseDefinition> $responses Response definitions
     * @return \Crustum\Ai\TestSuite\Http\HttpResponseSequence
     */
    public static function sequence(array $responses): HttpResponseSequence
    {
        return new HttpResponseSequence($responses);
    }

    /**
     * Fake provider HTTP requests using a Guzzle mock handler.
     *
     * @param callable|array<string, mixed>|null $definition URL patterns or callback
     * @return void
     */
    public static function fake(array|callable|null $definition = null): void
    {
        self::reset();
        self::installHandler();

        if ($definition === null) {
            return;
        }

        if (is_callable($definition)) {
            self::fakeWithCallback($definition);

            return;
        }

        foreach ($definition as $pattern => $response) {
            self::registerPatternMocks((string)$pattern, $response);
        }
    }

    /**
     * Get recorded HTTP request pairs.
     *
     * @param callable|null $filter Optional request filter
     * @return array<int, array{0: \Crustum\Ai\TestSuite\Http\RecordedHttp, 1: \Crustum\Ai\Http\Response}>
     */
    public static function recorded(?callable $filter = null): array
    {
        $pairs = [];

        foreach (HttpClientFactory::history() as $entry) {
            $request = $entry['request'] ?? null;

            if (!$request instanceof RequestInterface) {
                continue;
            }

            $pairs[] = [
                RecordedHttp::fromPsrRequest($request),
                $entry['response'],
            ];
        }

        if ($filter === null) {
            return $pairs;
        }

        return array_values(array_filter(
            $pairs,
            fn(array $pair): bool => $filter($pair[0]),
        ));
    }

    /**
     * Get recorded requests only.
     *
     * @return array<int, \Crustum\Ai\TestSuite\Http\RecordedHttp>
     */
    public static function recordedRequests(): array
    {
        return array_map(
            fn(array $pair): RecordedHttp => $pair[0],
            self::recorded(),
        );
    }

    /**
     * Get a timeline summary for assertion failures.
     *
     * @return string
     */
    public static function timeline(): string
    {
        $requests = self::recordedRequests();

        if ($requests === []) {
            return 'Recorded (0): none';
        }

        $lines = ['Recorded (' . count($requests) . '):'];

        foreach ($requests as $index => $request) {
            $lines[] = '  [' . $index . '] ' . $request->summary();
        }

        return implode("\n", $lines);
    }

    /**
     * Determine if a URL pattern matches a request.
     *
     * @param string $pattern URL pattern
     * @param string $method HTTP method
     * @param string $url Request URL
     * @return bool
     */
    public static function patternMatches(string $pattern, string $method, string $url): bool
    {
        if (str_contains($pattern, ' ')) {
            [$patternMethod, $patternUrl] = explode(' ', $pattern, 2);

            if (strtoupper($patternMethod) !== strtoupper($method)) {
                return false;
            }

            $pattern = $patternUrl;
        }

        if ($pattern === '*') {
            return true;
        }

        $regex = '/' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '/i';

        return (bool)preg_match($regex, $url);
    }

    /**
     * Reset provider HTTP fake state.
     *
     * @return void
     */
    public static function reset(): void
    {
        HttpClientFactory::resetTestHandler();

        self::$mocks = [];
        self::$callback = null;
        self::$catchAll = null;
    }

    /**
     * Register catch-all HTTP mocks.
     *
     * @param mixed $response Response definition or sequence
     * @return void
     */
    protected static function fakeWithCatchAllResponse(mixed $response): void
    {
        $definitions = $response instanceof HttpResponseSequence
            ? $response->definitions()
            : [self::normalizeResponse($response)];
        $index = 0;

        self::$catchAll = function () use ($definitions, &$index): Response {
            $definition = $definitions[min($index, count($definitions) - 1)];
            $index++;

            return $definition->toResponse();
        };
    }

    /**
     * Register callback-driven HTTP mocks.
     *
     * @param callable $callback Response resolver callback
     * @return void
     */
    protected static function fakeWithCallback(callable $callback): void
    {
        self::$callback = $callback;
    }

    /**
     * Register HTTP mocks for a URL pattern.
     *
     * @param string $pattern URL pattern
     * @param mixed $response Response definition or sequence
     * @return void
     */
    protected static function registerPatternMocks(string $pattern, mixed $response): void
    {
        if ($pattern === '*') {
            self::fakeWithCatchAllResponse($response);

            return;
        }

        [$methods, $urls] = self::resolvePattern($pattern);

        if ($response instanceof HttpResponseSequence) {
            $definitions = $response->definitions();
            $index = 0;

            foreach ($methods as $method) {
                foreach ($urls as $url) {
                    self::$mocks[] = [
                        'method' => $method,
                        'pattern' => $url,
                        'resolve' => function () use ($definitions, &$index): Response {
                            $definition = $definitions[min($index, count($definitions) - 1)];
                            $index++;

                            return $definition->toResponse();
                        },
                    ];
                }
            }

            return;
        }

        $responseDefinition = self::normalizeResponse($response);

        foreach ($methods as $method) {
            foreach ($urls as $url) {
                self::$mocks[] = [
                    'method' => $method,
                    'pattern' => $url,
                    'resolve' => $responseDefinition->toResponse(...),
                ];
            }
        }
    }

    /**
     * Resolve an HTTP fake pattern into mock targets.
     *
     * @param string $pattern URL pattern
     * @return array{0: list<string>, 1: list<string>}
     */
    protected static function resolvePattern(string $pattern): array
    {
        $methods = ['GET', 'POST', 'DELETE', 'PUT', 'PATCH'];

        if (preg_match('/^(GET|POST|PUT|PATCH|DELETE)\s+(.+)$/i', $pattern, $matches)) {
            $methods = [strtoupper($matches[1])];
            $pattern = $matches[2];
        }

        if ($pattern === '*') {
            return [$methods, []];
        }

        if (!str_starts_with($pattern, 'http://') && !str_starts_with($pattern, 'https://')) {
            $pattern = 'https://' . ltrim($pattern, '/');
        }

        if (str_ends_with($pattern, '*') && !str_ends_with($pattern, '/*')) {
            $pattern = rtrim($pattern, '*') . '/*';
        }

        return [$methods, [$pattern]];
    }

    /**
     * Normalize a fake response value.
     *
     * @param mixed $response Response value
     * @return \Crustum\Ai\TestSuite\Http\HttpResponseDefinition
     */
    protected static function normalizeResponse(mixed $response): HttpResponseDefinition
    {
        if ($response instanceof HttpResponseDefinition) {
            return $response;
        }

        if (is_array($response) || is_string($response)) {
            return self::response($response);
        }

        return self::response([]);
    }

    /**
     * Install the Guzzle test handler stack.
     *
     * @return void
     */
    protected static function installHandler(): void
    {
        $realHandler = Utils::chooseHandler();

        $handler = static function (RequestInterface $request, array $options) use ($realHandler) {
            if (self::$callback !== null) {
                $recorded = RecordedHttp::fromPsrRequest($request);
                $definition = (self::$callback)($recorded);

                return Create::promiseFor(self::normalizeResponse($definition)->toResponse());
            }

            foreach (self::$mocks as $mock) {
                if (self::mockMatches($mock, $request)) {
                    return Create::promiseFor($mock['resolve']());
                }
            }

            if (self::$catchAll !== null) {
                return Create::promiseFor((self::$catchAll)());
            }

            return $realHandler($request, $options);
        };

        HttpClientFactory::useTestHandler(new HandlerStack($handler));
    }

    /**
     * Determine whether a mock matches a request.
     *
     * @param array{method: string|null, pattern: string, resolve: callable(): \Crustum\Ai\Http\Response} $mock Mock definition
     * @param \Psr\Http\Message\RequestInterface $request Request
     * @return bool
     */
    protected static function mockMatches(array $mock, RequestInterface $request): bool
    {
        if ($mock['method'] !== null && strtoupper($mock['method']) !== strtoupper($request->getMethod())) {
            return false;
        }

        return self::patternMatches($mock['pattern'], $request->getMethod(), (string)$request->getUri());
    }
}
