<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Storage;

/**
 * Shared filesystem helpers for test storage roots under TMP.
 */
class TmpCleanup
{
    /**
     * Recursively delete a directory and its contents.
     *
     * @param string $directory Absolute directory path.
     * @return void
     */
    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.') {
                continue;
            }

            if ($item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                static::removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
