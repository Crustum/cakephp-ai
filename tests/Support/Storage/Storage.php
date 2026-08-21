<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Storage;

use Cake\Core\Configure;
use Crustum\Ai\Filesystem\LocalFlysystem;
use League\Flysystem\FilesystemOperator;

/**
 * Test helper for local Flysystem operators.
 */
class Storage
{
    /**
     * Create a fresh local Flysystem root for tests and set Ai.filesystem config.
     *
     * @param string $name Logical name used only for the temp directory suffix.
     * @return \League\Flysystem\FilesystemOperator
     */
    public static function fake(string $name = 'local'): FilesystemOperator
    {
        $root = TMP . 'ai_test_storage_' . $name;

        if (is_dir($root)) {
            TmpCleanup::removeDirectory($root);
        }

        mkdir($root, 0777, true);

        Configure::write('Ai.filesystem.root', $root);
        Configure::write('Ai.filesystem.url', '/storage');

        return LocalFlysystem::create($root);
    }

    /**
     * Remove a fake Flysystem root from TMP.
     *
     * @param string $name Logical name used for the temp directory suffix.
     * @return void
     */
    public static function cleanup(string $name = 'local'): void
    {
        TmpCleanup::removeDirectory(TMP . 'ai_test_storage_' . $name);
    }

    /**
     * Get a helper bound to the current test filesystem root.
     *
     * @return FakeFilesystem
     */
    public static function filesystem(): FakeFilesystem
    {
        return new FakeFilesystem();
    }
}
