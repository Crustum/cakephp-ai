<?php
declare(strict_types=1);

namespace Crustum\Ai\Filesystem;

use Cake\Core\Configure;
use InvalidArgumentException;
use League\Flysystem\FilesystemOperator;

/**
 * Registry of named League Flysystem operators for Stored* attachments.
 *
 * Register any adapter (local, S3, …) via {@see self::register()}, or configure
 * a local root under `Ai.filesystem.named.{name}.root`.
 */
final class FilesystemRegistry
{
    /**
     * @var array<string, \League\Flysystem\FilesystemOperator>
     */
    private static array $operators = [];

    /**
     * Register a Flysystem operator under a name.
     *
     * @param string $name Operator name
     * @param \League\Flysystem\FilesystemOperator $filesystem Operator
     * @return void
     */
    public static function register(string $name, FilesystemOperator $filesystem): void
    {
        self::$operators[$name] = $filesystem;
    }

    /**
     * Forget one registered operator, or all when $name is null.
     *
     * @param string|null $name Operator name
     * @return void
     */
    public static function forget(?string $name = null): void
    {
        if ($name === null) {
            self::$operators = [];

            return;
        }

        unset(self::$operators[$name]);
    }

    /**
     * Resolve a named Flysystem operator.
     *
     * @param string|null $name Operator name (null = default)
     * @return \League\Flysystem\FilesystemOperator
     * @throws \InvalidArgumentException if the name is not configured
     */
    public static function get(?string $name = null): FilesystemOperator
    {
        $name ??= self::default();

        if (isset(self::$operators[$name])) {
            return self::$operators[$name];
        }

        return self::$operators[$name] = self::resolve($name);
    }

    /**
     * Get the default operator name.
     *
     * @return string
     */
    public static function default(): string
    {
        $default = Configure::read('Ai.filesystem.default') ?? 'local';

        return (string)$default;
    }

    /**
     * Build a Flysystem operator from configuration.
     *
     * @param string $name Operator name
     * @return \League\Flysystem\FilesystemOperator
     * @throws \InvalidArgumentException if the name is not configured
     */
    private static function resolve(string $name): FilesystemOperator
    {
        $configured = Configure::read('Ai.filesystem.named.' . $name);

        if ($configured instanceof FilesystemOperator) {
            return $configured;
        }

        if (is_array($configured) && !empty($configured['root'])) {
            return LocalFlysystem::create((string)$configured['root']);
        }

        $root = Configure::read(sprintf('Ai.filesystem.named.%s.root', $name));

        if (is_string($root) && $root !== '') {
            return LocalFlysystem::create($root);
        }

        if ($name === self::default() || $name === 'local') {
            return LocalFlysystem::fromConfig();
        }

        throw new InvalidArgumentException(sprintf('Filesystem [%s] is not configured.', $name));
    }
}
