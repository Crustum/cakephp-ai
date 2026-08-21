<?php
declare(strict_types=1);

use Crustum\Ai\Test\Support\Http\AiHttp;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Test\Support\Http\AiHttpSequence;

if (!function_exists('aiHttpResponse')) {
    /**
     * Create a fake HTTP response definition.
     *
     * @param array<string, mixed>|string $body Response body
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
     */
    function aiHttpResponse(
        array|string $body = [],
        int $status = 200,
        array $headers = [],
    ): AiHttpResponseDefinition {
        return new AiHttpResponseDefinition($body, $status, $headers);
    }
}

if (!function_exists('aiHttpSequence')) {
    /**
     * Create a sequence of fake HTTP responses.
     *
     * @param array<int, \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition> $responses Response definitions
     * @return \Crustum\Ai\Test\Support\Http\AiHttpSequence
     */
    function aiHttpSequence(array $responses): AiHttpSequence
    {
        return new AiHttpSequence($responses);
    }
}

if (!function_exists('aiHttpFake')) {
    /**
     * Fake provider HTTP requests.
     *
     * @deprecated 1.x Prefer `AiFlowTrait::fakeProviderHttp()` / `AiFlow::fakeProviderHttp()`.
     * @param callable|array<string, mixed>|null $definition URL patterns or callback
     * @return void
     */
    function aiHttpFake(array|callable|null $definition = null): void
    {
        AiHttp::fake($definition);
    }
}

if (!function_exists('aiHttpRecorded')) {
    /**
     * Get recorded HTTP request pairs.
     *
     * @deprecated 1.x Prefer `AiFlow::getHttpRequests()` / `AiFlowTrait::getHttpRequests()`.
     * @param callable|null $filter Optional request filter
     * @return array<int, array{0: \Crustum\Ai\Test\Support\Http\AiHttpRequest, 1: \Cake\Http\Client\Response}>
     */
    function aiHttpRecorded(?callable $filter = null): array
    {
        return AiHttp::recorded($filter);
    }
}

if (!function_exists('aiAssertHttpSent')) {
    /**
     * Assert that a matching HTTP request was sent.
     *
     * @deprecated 1.x Prefer `AiFlowTrait::assertHttpSent()` / `AiFlow::assertHttpSent()`.
     * @param callable $callback Truth test callback
     * @return void
     */
    function aiAssertHttpSent(callable $callback): void
    {
        AiHttp::assertSent($callback);
    }
}

if (!function_exists('aiAssertHttpSentCount')) {
    /**
     * Assert the number of recorded HTTP requests.
     *
     * @deprecated 1.x Prefer `AiFlowTrait::assertHttpSentCount()` / `AiFlow::assertHttpSentCount()`.
     * @param int $count Expected count
     * @return void
     */
    function aiAssertHttpSentCount(int $count): void
    {
        AiHttp::assertSentCount($count);
    }
}

if (!function_exists('aiAssertHttpSentInOrder')) {
    /**
     * Assert that HTTP requests were sent in a specific order.
     *
     * @deprecated 1.x Prefer `AiFlowTrait::assertHttpSentInOrder()` / `AiFlow::assertHttpSentInOrder()`.
     * @param array<int, callable> $callbacks Ordered truth tests
     * @return void
     */
    function aiAssertHttpSentInOrder(array $callbacks): void
    {
        AiHttp::assertSentInOrder($callbacks);
    }
}

if (!function_exists('aiAssertHttpNothingSent')) {
    /**
     * Assert that no HTTP requests were sent.
     *
     * @deprecated 1.x Prefer `AiFlowTrait::assertHttpNothingSent()` / `AiFlow::assertHttpNothingSent()`.
     * @return void
     */
    function aiAssertHttpNothingSent(): void
    {
        AiHttp::assertNothingSent();
    }
}

if (!function_exists('aiStopHttpFake')) {
    /**
     * Reset provider HTTP fake state.
     *
     * @deprecated 1.x Prefer `AiFlow::reset()` (also run by `AiFlowTrait` teardown).
     * @return void
     */
    function aiStopHttpFake(): void
    {
        AiHttp::stop();
    }
}

if (!function_exists('aiHttpPatternMatches')) {
    /**
     * Determine if a URL pattern matches a request.
     *
     * @param string $pattern URL pattern
     * @param string $method HTTP method
     * @param string $url Request URL
     * @return bool
     */
    function aiHttpPatternMatches(string $pattern, string $method, string $url): bool
    {
        return AiHttp::patternMatches($pattern, $method, $url);
    }
}

if (!function_exists('aiRegisterTextProvider')) {
    /**
     * Register a custom text provider instance for tests.
     *
     * @param string $name Provider name
     * @param object $provider Provider instance
     * @return void
     */
    function aiRegisterTextProvider(string $name, object $provider): void
    {
        AiHttp::registerTextProvider($name, $provider);
    }
}

if (!function_exists('sentRequest')) {
    /**
     * Get the first recorded HTTP request.
     *
     * @deprecated 1.x Prefer `AiFlow::getFirstHttpRequest()`.
     * @return \Crustum\Ai\Test\Support\Http\AiHttpRequest
     */
    function sentRequest(): AiHttpRequest
    {
        return AiHttp::sentRequest();
    }
}

if (!function_exists('multipartField')) {
    /**
     * Get a multipart form field from a recorded request.
     *
     * @param \Crustum\Ai\Test\Support\Http\AiHttpRequest $request Request instance
     * @param string $field Field name
     * @return string|null
     */
    function multipartField(AiHttpRequest $request, string $field): ?string
    {
        return AiHttp::multipartField($request, $field);
    }
}

if (!function_exists('multipartNestedField')) {
    /**
     * Get a nested multipart form field value as a flattened list.
     *
     * @param \Crustum\Ai\Test\Support\Http\AiHttpRequest $request Request instance
     * @param string $field Field name
     * @return array<int, mixed>
     */
    function multipartNestedField(AiHttpRequest $request, string $field): array
    {
        return AiHttp::multipartNestedField($request, $field);
    }
}
