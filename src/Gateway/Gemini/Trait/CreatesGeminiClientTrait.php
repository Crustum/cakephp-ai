<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

use Cake\Http\Client\FormData;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Http\Contract\HttpClientAdapterInterface;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Utility\Value;
use RuntimeException;

/**
 * Creates HTTP clients for the Gemini API.
 */
trait CreatesGeminiClientTrait
{
    use MergesHeadersTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $geminiHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $geminiHttpTimeout = 60;

    /**
     * Whether the pending HTTP request should stream.
     */
    protected bool $geminiHttpStream = false;

    /**
     * Whether the pending request should use the upload base URL.
     */
    protected bool $geminiHttpUseUploadBaseUrl = false;

    /**
     * Multipart attachments for the pending HTTP request.
     *
     * @var array<int, array{field: string, content: string, filename: string|null, headers: array<string, string>}>
     */
    protected array $geminiHttpAttachments = [];

    /**
     * Configure an HTTP client for the Gemini API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int|null $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, ?int $timeout = null): static
    {
        $this->geminiHttpProvider = $provider;
        $this->geminiHttpTimeout = $timeout ?? 60;
        $this->geminiHttpStream = false;
        $this->geminiHttpUseUploadBaseUrl = false;
        $this->geminiHttpAttachments = [];

        return $this;
    }

    /**
     * Route the pending request through the Gemini upload base URL.
     */
    protected function usingUploadBaseUrl(): static
    {
        $this->geminiHttpUseUploadBaseUrl = true;

        return $this;
    }

    /**
     * Set HTTP client options for the pending request.
     *
     * @param array<string, mixed> $options HTTP options
     */
    protected function withOptions(array $options): static
    {
        $this->geminiHttpStream = (bool)($options['stream'] ?? false);

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
        $this->geminiHttpAttachments[] = [
            'field' => $field,
            'content' => $content,
            'filename' => $filename,
            'headers' => $headers,
        ];

        return $this;
    }

    /**
     * Send a POST request to the Gemini API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body = []): HttpResponseInterface
    {
        $provider = $this->geminiHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('Gemini HTTP provider is not configured.');
        }

        $baseUrl = $this->geminiHttpUseUploadBaseUrl ? $this->uploadBaseUrl($provider) : $this->baseUrl($provider);
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        $http = $this->createHttpClient($this->geminiHttpTimeout);

        if ($this->geminiHttpAttachments !== []) {
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
            'Accept' => $this->geminiHttpStream ? 'text/event-stream' : 'application/json',
        ]);

        $options = [
            'headers' => $headers,
        ];

        if ($this->geminiHttpStream) {
            $options['stream'] = true;
        }

        return $http->post($url, json_encode($body) ?: '{}', $options);
    }

    /**
     * Send a GET request to the Gemini API.
     *
     * @param string $path API path
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function get(string $path): HttpResponseInterface
    {
        $provider = $this->geminiHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('Gemini HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');

        $headers = array_merge($this->authHeaders($provider), [
            'Accept' => 'application/json',
        ]);

        return $this->createHttpClient($this->geminiHttpTimeout)->get($url, [], [
            'headers' => $headers,
        ]);
    }

    /**
     * Send a DELETE request to the Gemini API.
     *
     * @param string $path API path
     * @param array<string, mixed> $query Query parameters
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function delete(string $path, array $query = []): HttpResponseInterface
    {
        $provider = $this->geminiHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('Gemini HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');

        $headers = array_merge($this->authHeaders($provider), [
            'Accept' => 'application/json',
        ]);

        return $this->createHttpClient($this->geminiHttpTimeout)->delete($url, $query, [
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
     * Get authorization headers for the Gemini API.
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
            'x-goog-api-key' => $key,
        ], $provider->additionalConfiguration()['headers'] ?? []);
    }

    /**
     * Get the base URL for the Gemini API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim((string)($provider->additionalConfiguration()['url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    /**
     * Get the upload base URL for the Gemini API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function uploadBaseUrl(Provider $provider): string
    {
        return str_replace('/v1beta', '/upload/v1beta', $this->baseUrl($provider));
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

        foreach ($this->geminiHttpAttachments as $attachment) {
            $part = $formData->newPart($attachment['field'], $attachment['content']);
            $part->filename($attachment['filename'] ?? 'file');
            $part->type($attachment['headers']['Content-Type'] ?? 'application/octet-stream');
            $formData->add($part);
        }

        return $formData;
    }
}
