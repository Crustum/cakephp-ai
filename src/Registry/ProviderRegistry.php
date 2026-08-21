<?php
declare(strict_types=1);

namespace Crustum\Ai\Registry;

use Cake\Core\App;
use Cake\Core\Configure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Provider Registry
 *
 * Manages loading and caching of AI provider instances.
 * Providers are configured in config/ai.php and instantiated on demand.
 */
class ProviderRegistry
{
    /**
     * Loaded provider instances
     *
     * @var array<string, object>
     */
    protected array $loaded = [];

    /**
     * Load a provider by name
     *
     * @param string $name Provider name from configuration
     * @return object Provider instance
     * @throws \InvalidArgumentException When provider configuration is missing or invalid
     * @throws \RuntimeException When provider class cannot be instantiated
     */
    public function load(string $name): object
    {
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }

        $config = Configure::read('Ai.providers.' . $name);
        if (!$config) {
            throw new InvalidArgumentException(sprintf("Provider '%s' is not configured.", $name));
        }

        if (empty($config['className'])) {
            throw new InvalidArgumentException(sprintf("Provider '%s' is missing 'className' in configuration.", $name));
        }

        $className = App::className($config['className'], 'Ai/Providers');
        if (!$className) {
            $className = $config['className'];
        }

        if (!class_exists($className)) {
            throw new RuntimeException(sprintf("Provider class '%s' not found.", $className));
        }

        $config['name'] ??= $name;
        $config['driver'] ??= $name;

        $this->loaded[$name] = new $className($config);

        return $this->loaded[$name];
    }

    /**
     * Check if a provider has been loaded
     *
     * @param string $name Provider name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->loaded[$name]);
    }

    /**
     * Get a loaded provider instance
     *
     * @param string $name Provider name
     * @return object|null Provider instance or null if not loaded
     */
    public function get(string $name): ?object
    {
        return $this->loaded[$name] ?? null;
    }

    /**
     * Unload a provider instance
     *
     * @param string $name Provider name
     * @return void
     */
    public function unload(string $name): void
    {
        unset($this->loaded[$name]);
    }

    /**
     * Register a pre-built provider instance for tests.
     *
     * @param string $name Provider name
     * @param object $instance Provider instance
     * @return void
     */
    public function registerInstance(string $name, object $instance): void
    {
        $this->loaded[$name] = $instance;
    }

    /**
     * Clear all loaded provider instances
     *
     * @return void
     */
    public function clear(): void
    {
        $this->loaded = [];
    }
}
