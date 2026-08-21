<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Mistral\Trait;

use Cake\Http\Client\FormData;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Http\Contract\HttpClientAdapterInterface;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Utility\Value;
use RuntimeException;

/**
 * Creates HTTP clients for the Mistral API.
 */
trait CreatesMistralClientTrait
{
    use MergesHeadersTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $mistralHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $mistralHttpTimeout = 60;

    /**
     * Whether the pending HTTP request should stream.
     */
    protected bool $mistralHttpStream = false;

    /**
     * Multipart attachments for the pending HTTP request.
     *
     * @var array<int, array{field: string, content: string, filename: string|null, headers: array<string, string>}>
     */
    protected array $mistralHttpAttachments = [];

    /**
     * Configure an HTTP client for the Mistral API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int|null $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, ?int $timeout = null): static
    {
        $this->mistralHttpProvider = $provider;
        $this->mistralHttpTimeout = $timeout ?? 60;
        $this->mistralHttpStream = false;
        $this->mistralHttpAttachments = [];

        return $this;
    }

    /**
     * Set HTTP client options for the pending request.
     *
     * @param array<string, mixed> $options HTTP options
     */
    protected function withOptions(array $options): static
    {
        $this->mistralHttpStream = (bool)($options['stream'] ?? false);

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
        $this->mistralHttpAttachments[] = [
            'field' => $field,
            'content' => $content,
            'filename' => $filename,
            'headers' => $headers,
        ];

        return $this;
    }

    /**
     * Send a POST request to the Mistral API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body = []): HttpResponseInterface
    {
        $provider = $this->mistralHttpProvider;

        if ($provider === null) {
            throw new RuntimeException('Mistral HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');

        $http = $this->createHttpClient($this->mistralHttpTimeout);

        if ($this->mistralHttpAttachments !== []) {
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
            'Accept' => $this->mistralHttpStream ? 'text/event-stream' : 'application/json',
        ]);

        $options = [
            'headers' => $headers,
        ];

        if ($this->mistralHttpStream) {
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
     * Get authorization headers for the Mistral API.
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
     * Get the base URL for the Mistral API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim((string)($provider->additionalConfiguration()['url'] ?? 'https://api.mistral.ai/v1'), '/');
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
            foreach (is_array($value) ? array_values($value) : [$value] as $item) {
                $formData->add($name, is_scalar($item) ? (string)$item : (json_encode($item) ?: ''));
            }
        }

        foreach ($this->mistralHttpAttachments as $attachment) {
            $part = $formData->newPart($attachment['field'], $attachment['content']);
            $part->filename($attachment['filename'] ?? 'file');
            $part->type($attachment['headers']['Content-Type'] ?? 'application/octet-stream');
            $formData->add($part);
        }

        return $formData;
    }
}
