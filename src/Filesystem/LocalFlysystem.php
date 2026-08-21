<?php
declare(strict_types=1);

namespace Crustum\Ai\Filesystem;

use Cake\Core\Configure;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Creates local league/flysystem operators from a root path.
 *
 * Pass a root path or inject a {@see FilesystemOperator} into filesystem tools directly.
 */
final class LocalFlysystem
{
    /**
     * Create a local Flysystem operator rooted at the given path.
     *
     * @param string $root Absolute filesystem root.
     * @return \League\Flysystem\FilesystemOperator
     */
    public static function create(string $root): FilesystemOperator
    {
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        return new Filesystem(new LocalFilesystemAdapter($root));
    }

    /**
     * Create a local Flysystem operator from Ai plugin config.
     *
     * Config keys: `Ai.filesystem.root` (path), optional `Ai.filesystem.url` (public URL base for GetFileUrl).
     *
     * @return \League\Flysystem\FilesystemOperator
     */
    public static function fromConfig(): FilesystemOperator
    {
        $root = Configure::read('Ai.filesystem.root', TMP . 'ai_storage');

        return self::create((string)$root);
    }
}
