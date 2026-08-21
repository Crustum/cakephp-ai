<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Xai\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Http\Contract\HttpClientAdapterInterface;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Utility\Value;
use RuntimeException;

/**
 * Creates HTTP clients for the xAI API.
 */
trait CreatesXaiClientTrait
{
    use MergesHeadersTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $xaiHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $xaiHttpTimeout = 60;

    /**
     * Whether the pending HTTP request should stream.
     */
    protected bool $xaiHttpStream = false;

    /**
     * Configure an HTTP client for the xAI API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int|null $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, ?int $timeout = null): static
    {
        $this->xaiHttpProvider = $provider;
        $this->xaiHttpTimeout = $timeout ?? 60;
        $this->xaiHttpStream = false;

        return $this;
    }

    /**
     * Set HTTP client options for the pending request.
     *
     * @param array<string, mixed> $options HTTP options
     */
    protected function withOptions(array $options): static
    {
        $this->xaiHttpStream = (bool)($options['stream'] ?? false);

        return $this;
    }

    /**
     * Send a POST request to the xAI API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body = []): HttpResponseInterface
    {
        $provider = $this->xaiHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('xAI HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');

        $headers = array_merge($this->authHeaders($provider), [
            'Content-Type' => 'application/json',
            'Accept' => $this->xaiHttpStream ? 'text/event-stream' : 'application/json',
        ]);

        $options = [
            'headers' => $headers,
        ];

        if ($this->xaiHttpStream) {
            $options['stream'] = true;
        }

        $http = $this->createHttpClient($this->xaiHttpTimeout);

        return $http->post($url, json_encode($body) ?: '{}', $options);
    }

    /**
     * Create an HTTP client for provider requests.
     *
     * @param int $timeout Request timeout in seconds
     * @return \Crustum\Ai\Http\Contract\HttpClientAdapterInterface
     */
    protected function createHttpClient(int $timeout): HttpClientAdapterInterface
    {
        return HttpClientFactory::create($timeout);
    }

    /**
     * Get authorization headers for the xAI API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, string>
     */
    protected function authHeaders(Provider $provider): array
    {
        $key = $provider->providerCredentials()['key'] ?? null;

        if (!Value::filled($key)) {
            return $this->mergeConfiguredHeaders([], $provider->additionalConfiguration()['headers'] ?? []);
        }

        return $this->mergeConfiguredHeaders([
            'Authorization' => 'Bearer ' . $key,
        ], $provider->additionalConfiguration()['headers'] ?? []);
    }

    /**
     * Get the base URL for the xAI API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim((string)($provider->additionalConfiguration()['url'] ?? 'https://api.x.ai/v1'), '/');
    }
}
