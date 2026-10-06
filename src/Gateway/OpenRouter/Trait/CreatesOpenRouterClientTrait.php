<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenRouter\Trait;

use Cake\Http\Client\FormData;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Http\Contract\HttpClientAdapterInterface;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use RuntimeException;

/**
 * Creates HTTP clients for the OpenRouter API.
 */
trait CreatesOpenRouterClientTrait
{
    use MergesHeadersTrait;

    /**
     * Active provider for the pending HTTP request.
     *
     * @var \Crustum\Ai\Contracts\Providers\Provider|null
     */
    protected ?Provider $openRouterHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $openRouterHttpTimeout = 60;

    /**
     * Whether the pending HTTP request should stream.
     */
    protected bool $openRouterHttpStream = false;

    /**
     * Multipart attachments for the pending HTTP request.
     *
     * @var array<int, array{field: string, content: string, filename: string|null, headers: array<string, string>}>
     */
    protected array $openRouterHttpAttachments = [];

    /**
     * Configure an HTTP client for the OpenRouter API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int|null $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, ?int $timeout = null): static
    {
        $this->openRouterHttpProvider = $provider;
        $this->openRouterHttpTimeout = $timeout ?? 60;
        $this->openRouterHttpStream = false;
        $this->openRouterHttpAttachments = [];

        return $this;
    }

    /**
     * Set HTTP client options for the pending request.
     *
     * @param array<string, mixed> $options HTTP options
     */
    protected function withOptions(array $options): static
    {
        $this->openRouterHttpStream = (bool)($options['stream'] ?? false);

        return $this;
    }

    /**
     * Attach a file to the pending multipart request.
     *
     * @param string $field Form field name
     * @param string $content File content
     * @param string|null $filename File name
     * @param array<string, string> $headers Additional part headers
     */
    protected function attach(string $field, string $content, ?string $filename = null, array $headers = []): static
    {
        $this->openRouterHttpAttachments[] = [
            'field' => $field,
            'content' => $content,
            'filename' => $filename,
            'headers' => $headers,
        ];

        return $this;
    }

    /**
     * Send a POST request to the OpenRouter API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body): HttpResponseInterface
    {
        $provider = $this->openRouterHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('OpenRouter HTTP provider is not configured.');
        }

        $config = $provider->additionalConfiguration();
        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');

        $http = $this->createHttpClient($this->openRouterHttpTimeout);

        if ($this->openRouterHttpAttachments !== []) {
            $formData = $this->buildMultipartFormData($body);

            $headers = $this->mergeConfiguredHeaders(array_filter([
                'Authorization' => 'Bearer ' . ($provider->providerCredentials()['key'] ?? ''),
                'HTTP-Referer' => $config['http_referer'] ?? null,
                'X-OpenRouter-Title' => $config['x_title'] ?? null,
                'Content-Type' => $formData->contentType(),
            ]), $config['headers'] ?? []);

            $response = $http->post($url, (string)$formData, [
                'headers' => $headers,
            ]);

            $this->openRouterHttpAttachments = [];

            return $response;
        }

        $headers = $this->mergeConfiguredHeaders(array_filter([
            'Authorization' => 'Bearer ' . ($provider->providerCredentials()['key'] ?? ''),
            'HTTP-Referer' => $config['http_referer'] ?? null,
            'X-OpenRouter-Title' => $config['x_title'] ?? null,
            'Content-Type' => 'application/json',
            'Accept' => $this->openRouterHttpStream ? 'text/event-stream' : 'application/json',
        ]), $config['headers'] ?? []);

        $options = [
            'headers' => $headers,
        ];

        if ($this->openRouterHttpStream) {
            $options['stream'] = true;
        }

        return $http->post($url, json_encode($body) ?: '{}', $options);
    }

    /**
     * Send a GET request to the OpenRouter API.
     *
     * @param string $path API path
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function get(string $path): HttpResponseInterface
    {
        $provider = $this->openRouterHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('OpenRouter HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');
        $headers = $this->authHeaders($provider);

        $http = $this->createHttpClient($this->openRouterHttpTimeout);

        return $http->get($url, [], [
            'headers' => $headers,
        ]);
    }

    /**
     * Send a DELETE request to the OpenRouter API.
     *
     * @param string $path API path
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function delete(string $path): HttpResponseInterface
    {
        $provider = $this->openRouterHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('OpenRouter HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');
        $headers = $this->authHeaders($provider);

        $http = $this->createHttpClient($this->openRouterHttpTimeout);

        return $http->delete($url, [], [
            'headers' => $headers,
        ]);
    }

    /**
     * Get authorization headers for the OpenRouter API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, string>
     */
    protected function authHeaders(Provider $provider): array
    {
        $config = $provider->additionalConfiguration();

        return $this->mergeConfiguredHeaders(array_filter([
            'Authorization' => 'Bearer ' . ($provider->providerCredentials()['key'] ?? ''),
            'HTTP-Referer' => $config['http_referer'] ?? null,
            'X-OpenRouter-Title' => $config['x_title'] ?? null,
        ]), $config['headers'] ?? []);
    }

    /**
     * Build multipart form data for file upload requests.
     *
     * @param array<string, mixed> $fields Form fields.
     * @return \Cake\Http\Client\FormData
     */
    protected function buildMultipartFormData(array $fields): FormData
    {
        $formData = new FormData();

        foreach ($fields as $name => $value) {
            $formData->add($name, is_scalar($value) ? (string)$value : (json_encode($value) ?: ''));
        }

        foreach ($this->openRouterHttpAttachments as $attachment) {
            $part = $formData->newPart($attachment['field'], $attachment['content']);
            $part->filename($attachment['filename'] ?? 'file');
            $part->type($attachment['headers']['Content-Type'] ?? 'application/octet-stream');
            $formData->add($part);
        }

        return $formData;
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
     * Get the base URL for the OpenRouter API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        $config = $provider->additionalConfiguration();

        return rtrim((string)($config['url'] ?? $config['baseUrl'] ?? 'https://openrouter.ai/api/v1'), '/');
    }
}
