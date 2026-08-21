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
     * Provider-specific options.
     *
     * @var \Laravel\SerializableClosure\SerializableClosure|\Closure|array<string, mixed>
     */
    protected array|Closure|SerializableClosure $providerOptions = [];

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
     * Resolve provider options for the given provider.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider instance
     * @return array<string, mixed>
     */
    protected function resolveProviderOptions(Provider $provider): array
    {
        if ($this->providerOptions instanceof SerializableClosure) {
            return ($this->providerOptions)($provider) ?: [];
        }

        if ($this->providerOptions instanceof Closure) {
            return ($this->providerOptions)($provider) ?: [];
        }

        return $this->providerOptions;
    }
}
