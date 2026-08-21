<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Ollama\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Http\Contract\HttpClientAdapterInterface;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Utility\Value;
use RuntimeException;

/**
 * Creates HTTP clients for the Ollama API.
 */
trait CreatesOllamaClientTrait
{
    use MergesHeadersTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $ollamaHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $ollamaHttpTimeout = 60;

    /**
     * Whether the pending HTTP request should stream.
     */
    protected bool $ollamaHttpStream = false;

    /**
     * Configure an HTTP client for the Ollama API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int|null $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, ?int $timeout = null): static
    {
        $this->ollamaHttpProvider = $provider;
        $this->ollamaHttpTimeout = $timeout ?? 60;
        $this->ollamaHttpStream = false;

        return $this;
    }

    /**
     * Set HTTP client options for the pending request.
     *
     * @param array<string, mixed> $options HTTP options
     */
    protected function withOptions(array $options): static
    {
        $this->ollamaHttpStream = (bool)($options['stream'] ?? false);

        return $this;
    }

    /**
     * Send a POST request to the Ollama API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body): HttpResponseInterface
    {
        $provider = $this->ollamaHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('Ollama HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');

        $headers = array_merge($this->authHeaders($provider), [
            'Content-Type' => 'application/json',
            'Accept' => $this->ollamaHttpStream ? 'application/x-ndjson' : 'application/json',
        ]);

        $http = $this->createHttpClient($this->ollamaHttpTimeout);

        $options = [
            'headers' => $headers,
        ];

        if ($this->ollamaHttpStream) {
            $options['stream'] = true;
        }

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
     * Get authorization headers for the Ollama API.
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
     * Get the base URL for the Ollama API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim((string)($provider->additionalConfiguration()['url'] ?? 'http://localhost:11434'), '/');
    }
}
