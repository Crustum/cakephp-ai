<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Crustum\Ai\Contracts\Gateway\ClassificationGateway;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Trait\AnswersQuestionsTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use RuntimeException;

/**
 * TypeSafe classification gateway.
 */
class TypeSafeGateway implements ClassificationGateway
{
    use AnswersQuestionsTrait;
    use HandlesFailoverErrorsTrait;
    use MergesHeadersTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $typeSafeHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $typeSafeHttpTimeout = 30;

    /**
     * Get the path of the endpoint that answers questions.
     *
     * @return string
     */
    protected function classificationEndpoint(): string
    {
        return '/systemone';
    }

    /**
     * Get the answering model, preferring the checkpoint a routing server such as laya-serve chose.
     *
     * @param array<string, mixed> $data Response data
     * @param string $model Model name
     * @return string
     */
    protected function answeringModel(array $data, string $model): string
    {
        return $data['routing']['model'] ?? $data['model'] ?? $model;
    }

    /**
     * Get an HTTP client for the TypeSafe API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, int $timeout = 30): static
    {
        $this->typeSafeHttpProvider = $provider;
        $this->typeSafeHttpTimeout = $timeout;

        return $this;
    }

    /**
     * Send a POST request to the TypeSafe API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body = []): HttpResponseInterface
    {
        $provider = $this->typeSafeHttpProvider;

        if (!$provider instanceof Provider) {
            throw new RuntimeException('TypeSafe HTTP provider is not configured.');
        }

        $config = $provider->additionalConfiguration();

        $url = rtrim((string)($config['url'] ?? 'https://api.typesafe.ai/v1'), '/') . '/' . ltrim($path, '/');

        $key = $provider->providerCredentials()['key'] ?? null;

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($key !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $headers = $this->mergeConfiguredHeaders($headers, $config['headers'] ?? []);

        $http = HttpClientFactory::create($this->typeSafeHttpTimeout);

        return $http->post($url, json_encode($body) ?: '{}', [
            'headers' => $headers,
        ]);
    }

    /**
     * The status codes that indicate a provider is transiently unavailable and the request should fail over.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return [529, 502, 503, 504, 520, 522, 524];
    }
}
