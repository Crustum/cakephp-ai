<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Http;

use Crustum\Ai\Ai;
use Crustum\Ai\TestSuite\AiFlow;
use Crustum\Ai\TestSuite\Capture\HttpCapture;
use Crustum\Ai\TestSuite\Http\RecordedHttp;

/**
 * Backwards-compatible adapter to the public AI HTTP TestSuite.
 *
 * @deprecated 1.x Prefer `Crustum\Ai\TestSuite\AiFlow` / `AiFlowTrait` for new tests.
 *             This adapter remains for existing Pest call sites.
 */
class AiHttp
{
    /**
     * Fake provider HTTP requests.
     *
     * @param array<string, mixed>|callable|null $definition URL patterns or callback
     * @return void
     */
    public static function fake(array|callable|null $definition = null): void
    {
        if (is_callable($definition)) {
            $callback = $definition;
            $definition = fn(RecordedHttp $request): mixed => $callback(
                AiHttpRequest::fromRecorded($request),
            );
        }

        AiFlow::fakeProviderHttp($definition);
    }

    /**
     * Get recorded HTTP request pairs.
     *
     * @param callable|null $filter Optional request filter
     * @return array<int, array{0: \Crustum\Ai\Test\Support\Http\AiHttpRequest, 1: \Cake\Http\Client\Response}>
     */
    public static function recorded(?callable $filter = null): array
    {
        $requests = array_map(
            fn(array $pair): array => [
                AiHttpRequest::fromRecorded($pair[0]),
                $pair[1],
            ],
            AiFlow::getHttpRequests(),
        );

        if ($filter === null) {
            return $requests;
        }

        return array_values(array_filter(
            $requests,
            fn(array $pair): bool => $filter($pair[0]),
        ));
    }

    /**
     * Assert that a matching HTTP request was sent.
     *
     * @param callable $callback Truth test callback
     * @return void
     */
    public static function assertSent(callable $callback): void
    {
        AiFlow::assertHttpSent(
            fn(RecordedHttp $request): bool => $callback(
                AiHttpRequest::fromRecorded($request),
            ),
        );
    }

    /**
     * Assert the number of recorded HTTP requests.
     *
     * @param int $count Expected count
     * @return void
     */
    public static function assertSentCount(int $count): void
    {
        AiFlow::assertHttpSentCount($count);
    }

    /**
     * Assert that HTTP requests were sent in a specific order.
     *
     * @param array<int, callable> $callbacks Ordered truth tests
     * @return void
     */
    public static function assertSentInOrder(array $callbacks): void
    {
        AiFlow::assertHttpSentInOrder(array_map(
            fn(callable $callback): callable => fn(RecordedHttp $request): bool => $callback(
                AiHttpRequest::fromRecorded($request),
            ),
            $callbacks,
        ));
    }

    /**
     * Assert that no HTTP requests were sent.
     *
     * @return void
     */
    public static function assertNothingSent(): void
    {
        AiFlow::assertHttpNothingSent();
    }

    /**
     * Reset provider HTTP fake state.
     *
     * @return void
     */
    public static function stop(): void
    {
        AiFlow::reset();
    }

    /**
     * Register a custom text provider instance for tests.
     *
     * @param string $name Provider name
     * @param object $provider Provider instance
     * @return void
     */
    public static function registerTextProvider(string $name, object $provider): void
    {
        Ai::getManager()->registerProviderInstance($name, $provider);
    }

    /**
     * Get the first recorded HTTP request.
     *
     * @return \Crustum\Ai\Test\Support\Http\AiHttpRequest
     */
    public static function sentRequest(): AiHttpRequest
    {
        return AiHttpRequest::fromRecorded(AiFlow::getFirstHttpRequest());
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
        return HttpCapture::patternMatches($pattern, $method, $url);
    }

    /**
     * Get a multipart form field from a recorded request.
     *
     * @param \Crustum\Ai\Test\Support\Http\AiHttpRequest $request Request instance
     * @param string $field Field name
     * @return string|null
     */
    public static function multipartField(AiHttpRequest $request, string $field): ?string
    {
        return isset($request->body[$field]) ? (string)$request->body[$field] : null;
    }

    /**
     * Get a nested multipart form field value as a flattened list.
     *
     * JSON-encoded nested parts are decoded and their values flattened.
     *
     * @param \Crustum\Ai\Test\Support\Http\AiHttpRequest $request Request instance
     * @param string $field Field name
     * @return array<int, mixed>
     */
    public static function multipartNestedField(AiHttpRequest $request, string $field): array
    {
        $value = $request->body[$field] ?? null;

        if ($value === null) {
            return [];
        }

        $decoded = json_decode((string)$value, true);

        if (!is_array($decoded)) {
            return [trim((string)$value)];
        }

        return array_values($decoded);
    }
}
