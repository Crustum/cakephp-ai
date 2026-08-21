<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Storage;

use Cake\Core\Configure;
use Crustum\Ai\Filesystem\FilesystemRegistry;
use Crustum\Ai\Filesystem\LocalFlysystem;

/**
 * Test helper for named League Flysystem operators used by Stored* file classes.
 */
class LocalDisk
{
    /**
     * Configure and register a local Flysystem operator for tests.
     *
     * @param string $name Operator name (matches Stored* `$filesystem` / toArray key).
     * @return string Absolute filesystem root path.
     */
    public static function fake(string $name): string
    {
        $path = TMP . 'ai_test_storage_' . $name;

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        Configure::write(sprintf('Ai.filesystem.named.%s.root', $name), $path);
        FilesystemRegistry::register($name, LocalFlysystem::create($path));

        return $path;
    }

    /**
     * Remove a fake filesystem root and forget its registered operator.
     *
     * @param string $name Operator name.
     * @return void
     */
    public static function cleanup(string $name): void
    {
        FilesystemRegistry::forget($name);
        TmpCleanup::removeDirectory(TMP . 'ai_test_storage_' . $name);
    }

    /**
     * Write a file through the named Flysystem operator.
     *
     * @param string $name Operator name.
     * @param string $path Relative file path.
     * @param string $contents File contents.
     * @return void
     */
    public static function put(string $name, string $path, string $contents): void
    {
        static::fake($name);
        FilesystemRegistry::get($name)->write($path, $contents);
    }
}
