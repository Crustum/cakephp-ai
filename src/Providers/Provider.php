<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Core\Configure;
use Cake\Event\EventManagerInterface;
use Cake\Utility\Security;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Gateway\Gateway;
use Crustum\Ai\Contracts\Providers\Provider as ProviderContract;
use Crustum\Ai\Enums\Lab;
use RuntimeException;
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
        return array_diff_key($this->config, array_flip(['driver', 'key', 'name', 'ondemand']));
    }

    /**
     * Get a provider instance that sends the given HTTP headers with each request.
     *
     * @param array<string, string> $headers HTTP headers to send
     * @return static
     * @internal
     */
    public function withHeaders(array $headers): static
    {
        if ($headers === []) {
            return $this;
        }

        $clone = clone $this;
        $clone->config['headers'] = array_merge($clone->config['headers'] ?? [], $headers);

        return $clone;
    }

    /**
     * Format the given provider / model list.
     *
     * @param \Crustum\Ai\Enums\Lab|self|array<int|string, mixed>|string|null $providers Provider(s)
     * @param string|null $model Model name
     * @return array<string, string|null>
     */
    public static function formatProviderAndModelList(self|Lab|array|string|null $providers, ?string $model = null): array
    {
        $providers ??= (string)Configure::read('Ai.defaultProvider', 'openrouter');

        if (!is_array($providers)) {
            return [self::nameOf($providers) => $model];
        }

        $result = [];
        foreach ($providers as $key => $value) {
            if (is_numeric($key)) {
                $result[self::nameOf($value)] = null;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get the name the given provider is resolved by.
     *
     * @param \Crustum\Ai\Enums\Lab|self|string $provider Provider reference
     * @return string
     */
    private static function nameOf(self|Lab|string $provider): string
    {
        return match (true) {
            $provider instanceof self => $provider->name(),
            $provider instanceof Lab => $provider->value,
            default => $provider,
        };
    }

    /**
     * Get the serializable representation of the provider.
     *
     * On-demand providers travel encrypted so credentials never land in the
     * queue payload; configured providers travel by name.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        if (!($this->config['ondemand'] ?? false)) {
            return ['name' => $this->name()];
        }

        return ['config' => Security::encrypt(json_encode($this->config, JSON_THROW_ON_ERROR), $this->encryptionKey())];
    }

    /**
     * Get the encryption key for on-demand provider payloads, derived from the application salt.
     *
     * @return string
     */
    private function encryptionKey(): string
    {
        return hash('sha256', Security::getSalt(), true);
    }

    /**
     * Restore the provider from its serialized representation.
     *
     * @param array<string, mixed> $data Serialized representation
     */
    public function __unserialize(array $data): void
    {
        $manager = Ai::manager();

        if (isset($data['config'])) {
            $decrypted = Security::decrypt($data['config'], $this->encryptionKey());

            if (!is_string($decrypted)) {
                throw new RuntimeException('Unable to decrypt on-demand provider configuration.');
            }

            /** @var array<string, mixed> $config */
            $config = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

            $provider = $manager->build($config);
        } else {
            $provider = $manager->provider($data['name']);
        }

        foreach (get_object_vars($provider) as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /**
     * Convert the provider to its string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        // Configured providers cast to their driver for backward compatibility...
        return $this->config['ondemand'] ?? false ? $this->name() : $this->driver();
    }
}
