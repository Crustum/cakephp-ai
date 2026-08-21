<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Core\Configure;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\Gateway;
use Crustum\Ai\Contracts\Providers\Provider as ProviderContract;
use Crustum\Ai\Enums\Lab;
use Stringable;

/**
 * Abstract Provider Base Class
 *
 * Base implementation for all AI providers.
 * Handles common provider functionality like configuration, credentials, and event management.
 */
abstract class Provider implements ProviderContract, Stringable
{
    /**
     * Constructor
     *
     * @param \Crustum\Ai\Contracts\Gateway\Gateway $gateway Gateway instance for AI operations
     * @param array<string, mixed> $config Provider configuration
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(
        protected Gateway $gateway,
        protected array $config,
        protected EventManagerInterface $events,
    ) {
    }

    /**
     * Get the name of the underlying AI provider.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->config['name'];
    }

    /**
     * Get the name of the underlying AI driver.
     *
     * @return string
     */
    public function driver(): string
    {
        return $this->config['driver'];
    }

    /**
     * Get the credentials for the underlying AI provider.
     *
     * @return array<string, mixed>
     */
    public function providerCredentials(): array
    {
        return [
            'key' => $this->config['key'],
        ];
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     *
     * @return array<string, mixed>
     */
    public function additionalConfiguration(): array
    {
        return array_diff_key($this->config, array_flip(['driver', 'key', 'name']));
    }

    /**
     * Format the given provider / model list.
     *
     * @param \Crustum\Ai\Enums\Lab|array<int|string, mixed>|string $providers Provider(s)
     * @param string|null $model Model name
     * @return array<string, string|null>
     */
    public static function formatProviderAndModelList(Lab|array|string|null $providers, ?string $model = null): array
    {
        if ($providers === null) {
            $providers = (string)Configure::read('Ai.defaultProvider', 'openrouter');
        }

        if ($providers instanceof Lab) {
            return [$providers->value => $model];
        }

        if (is_string($providers)) {
            return [$providers => $model];
        }

        $result = [];
        foreach ($providers as $key => $value) {
            if (is_numeric($key)) {
                $providerKey = ($value instanceof Lab ? $value->value : $value);
                $result[$providerKey] = null;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Convert the provider to its string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->driver();
    }
}
