<?php
declare(strict_types=1);

namespace Crustum\Ai\PendingResponses\Trait;

use Closure;
use Crustum\Ai\Contracts\Providers\Provider;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Trait for resolving provider-specific options.
 *
 * Provides functionality to specify and resolve provider-specific options
 * for AI requests, supporting both static arrays and dynamic closures.
 */
trait ResolvesProviderOptionsTrait
{
    /**
     * Request HTTP headers.
     *
     * @var \Laravel\SerializableClosure\SerializableClosure|\Closure|array<string, string>
     */
    protected array|Closure|SerializableClosure $headers = [];

    /**
     * Provider-specific options.
     *
     * @var \Laravel\SerializableClosure\SerializableClosure|\Closure|array<string, mixed>
     */
    protected array|Closure|SerializableClosure $providerOptions = [];

    /**
     * Specify HTTP headers for the request.
     *
     * @param \Closure(\Crustum\Ai\Contracts\Providers\Provider): ?array<string, string>|array<string, string> $headers Request headers
     * @return $this
     */
    public function withHeaders(array|Closure $headers)
    {
        $this->headers = $headers instanceof Closure
            ? new SerializableClosure($headers)
            : $headers;

        return $this;
    }

    /**
     * Specify provider-specific options for the request.
     *
     * @param \Closure(\Crustum\Ai\Contracts\Providers\Provider): ?array<string, mixed>|array<string, mixed> $options Provider options
     * @return $this
     */
    public function withProviderOptions(array|Closure $options)
    {
        $this->providerOptions = $options instanceof Closure
            ? new SerializableClosure($options)
            : $options;

        return $this;
    }

    /**
     * Resolve the request body options and HTTP headers for the given provider.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider instance
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    protected function resolveProviderOptionsAndHeaders(Provider $provider): array
    {
        return [
            $this->resolveFor($this->providerOptions, $provider),
            $this->resolveFor($this->headers, $provider),
        ];
    }

    /**
     * Get the serializable provider options recorded by queued fakes.
     *
     * @return array<string, mixed>
     */
    protected function queuedProviderOptions(): array
    {
        return is_array($this->providerOptions) ? $this->providerOptions : [];
    }

    /**
     * Resolve the given value against the provider, invoking it when it is a closure.
     *
     * @param \Laravel\SerializableClosure\SerializableClosure|\Closure|array<string, mixed> $value Value to resolve
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider instance
     * @return array<string, mixed>
     */
    private function resolveFor(array|Closure|SerializableClosure $value, Provider $provider): array
    {
        return $value instanceof Closure || $value instanceof SerializableClosure
            ? ($value)($provider) ?: []
            : $value;
    }
}
