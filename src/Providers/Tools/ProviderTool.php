<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Tools;

use Closure;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Enums\Lab;

/**
 * Base class for provider-specific tools.
 *
 * Provides functionality for attaching provider-specific options
 * to tool payloads, supporting both static arrays and dynamic closures.
 */
abstract class ProviderTool implements HasProviderOptions
{
    /**
     * Provider-specific options.
     *
     * @var \Closure|array<string, mixed>
     */
    protected array|Closure $providerOptions = [];

    /**
     * Attach provider-specific options to the tool payload.
     *
     * Closures may only capture serializable values.
     *
     * @param \Closure(\Crustum\Ai\Enums\Lab|string): ?array<string, mixed>|array<string, mixed> $options Provider options
     */
    public function withProviderOptions(array|Closure $options): static
    {
        $this->providerOptions = $options;

        return $this;
    }

    /**
     * Get the provider-specific options for the given provider.
     *
     * @param \Crustum\Ai\Enums\Lab|string $provider The provider
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($this->providerOptions instanceof Closure) {
            return ($this->providerOptions)($provider) ?: [];
        }

        return $this->providerOptions;
    }
}
