<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

/**
 * Provider Interface
 *
 * Base interface for all AI providers.
 * Defines common methods that all providers must implement.
 */
interface Provider
{
    /**
     * Get the name of the underlying AI provider.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Get the name of the underlying AI driver.
     *
     * @return string
     */
    public function driver(): string;

    /**
     * Get the credentials for the underlying AI provider.
     *
     * @return array<string, mixed>
     */
    public function providerCredentials(): array;

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     *
     * @return array<string, mixed>
     */
    public function additionalConfiguration(): array;
}
