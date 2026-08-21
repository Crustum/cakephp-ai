<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

use Crustum\Ai\Enums\Lab;

/**
 * HasProviderOptions Interface
 *
 * Allows agents to specify provider-specific configuration options that should be
 * passed to the AI provider when making requests.
 */
interface HasProviderOptions
{
    /**
     * Get the provider-specific options to be passed to the provider.
     *
     * Returns an associative array of configuration options specific to the given provider.
     * These options can control provider-specific features like temperature, top_p,
     * frequency_penalty, or other provider-specific parameters.
     *
     * @param \Crustum\Ai\Enums\Lab|string $provider The AI provider identifier.
     * @return array<string, mixed> The provider-specific options.
     */
    public function providerOptions(Lab|string $provider): array;
}
