<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Anthropic\Trait;

use Cake\Http\Client\FormData;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Http\Contract\HttpClientAdapterInterface;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Utility\Value;
use RuntimeException;

/**
 * Creates HTTP clients for the Anthropic API.
 */
trait CreatesAnthropicClientTrait
{
    use MergesHeadersTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $anthropicHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $anthropicHttpTimeout = 60;

    /**
     * Whether the pending HTTP request should stream.
     */
    protected bool $anthropicHttpStream = false;

    /**
     * Multipart attachments for the pending HTTP request.
     *
     * @var array<int, array{field: string, content: string, filename: string|null, headers: array<string, string>}>
     */
    protected array $anthropicHttpAttachments = [];

    /**
     * Configure an HTTP client for the Anthropic API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int|null $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, ?int $timeout = null): static
    {
        $this->anthropicHttpProvider = $provider;
        $this->anthropicHttpTimeout = $timeout ?? 60;
        $this->anthropicHttpStream = false;
        $this->anthropicHttpAttachments = [];

        return $this;
    }

    /**
     * Set HTTP client options for the pending request.
     *
     * @param array<string, mixed> $options HTTP options
     */
    protected function withOptions(array $options): static
    {
        $this->anthropicHttpStream = (bool)($options['stream'] ?? false);

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
        $this->anthropicHttpAttachments[] = [
            'field' => $field,
            'content' => $content,
            'filename' => $filename,
            'headers' => $headers,
        ];

        return $this;
    }

    /**
     * Send a POST request to the Anthropic API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body = []): HttpResponseInterface
    {
        $provider = $this->anthropicHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('Anthropic HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');

        $http = $this->createHttpClient($this->anthropicHttpTimeout);

        if ($this->anthropicHttpAttachments !== []) {
            $formData = $this->buildMultipartFormData($body);
            $headers = array_merge($this->authHeaders($provider), [
                'Content-Type' => $formData->contentType(),
            ]);

            return $http->post($url, (string)$formData, [
                'headers' => $headers,
            ]);
        }

        $headers = array_merge($this->authHeaders($provider), [
            'Content-Type' => 'application/json',
            'Accept' => $this->anthropicHttpStream ? 'text/event-stream' : 'application/json',
        ]);

        $options = [
            'headers' => $headers,
        ];

        if ($this->anthropicHttpStream) {
            $options['stream'] = true;
        }

        return $http->post($url, json_encode($body) ?: '{}', $options);
    }

    /**
     * Send a GET request to the Anthropic API.
     *
     * @param string $path API path
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function get(string $path): HttpResponseInterface
    {
        $provider = $this->anthropicHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('Anthropic HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');

        $headers = array_merge($this->authHeaders($provider), [
            'Accept' => 'application/json',
        ]);

        return $this->createHttpClient($this->anthropicHttpTimeout)->get($url, [], [
            'headers' => $headers,
        ]);
    }

    /**
     * Send a DELETE request to the Anthropic API.
     *
     * @param string $path API path
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function delete(string $path): HttpResponseInterface
    {
        $provider = $this->anthropicHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('Anthropic HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');

        $headers = array_merge($this->authHeaders($provider), [
            'Accept' => 'application/json',
        ]);

        return $this->createHttpClient($this->anthropicHttpTimeout)->delete($url, [], [
            'headers' => $headers,
        ]);
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
     * Get authorization headers for the Anthropic API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, string>
     */
    protected function authHeaders(Provider $provider): array
    {
        $config = $provider->additionalConfiguration();

        $key = $provider->providerCredentials()['key'] ?? null;

        $headers = array_filter([
            'anthropic-version' => $config['version'] ?? '2023-06-01',
            'anthropic-beta' => $config['anthropic_beta'] ?? 'web-fetch-2025-09-10',
        ]);

        if (Value::filled($key)) {
            $headers['x-api-key'] = $key;
        }

        return $this->mergeConfiguredHeaders($headers, $config['headers'] ?? []);
    }

    /**
     * Get the base URL for the Anthropic API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim((string)($provider->additionalConfiguration()['url'] ?? 'https://api.anthropic.com/v1'), '/');
    }

    /**
     * Build multipart form data for file upload requests.
     *
     * @param array<string, mixed> $fields Form fields
     * @return \Cake\Http\Client\FormData
     */
    protected function buildMultipartFormData(array $fields): FormData
    {
        $formData = new FormData();

        foreach ($fields as $name => $value) {
            $formData->add($name, is_scalar($value) ? (string)$value : (json_encode($value) ?: ''));
        }

        foreach ($this->anthropicHttpAttachments as $attachment) {
            $part = $formData->newPart($attachment['field'], $attachment['content']);
            $part->filename($attachment['filename'] ?? 'file');
            $part->type($attachment['headers']['Content-Type'] ?? 'application/octet-stream');
            $formData->add($part);
        }

        return $formData;
    }
}
