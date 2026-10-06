<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Crustum\Ai\Tools\Filesystem\CopyFile;
use Crustum\Ai\Tools\Filesystem\DeleteFile;
use Crustum\Ai\Tools\Filesystem\FileExists;
use Crustum\Ai\Tools\Filesystem\GetFileMetadata;
use Crustum\Ai\Tools\Filesystem\GetFileUrl;
use Crustum\Ai\Tools\Filesystem\ListFiles;
use Crustum\Ai\Tools\Filesystem\MoveFile;
use Crustum\Ai\Tools\Filesystem\ReadFile;
use Crustum\Ai\Tools\Filesystem\WriteFile;
use League\Flysystem\FilesystemOperator;

/**
 * Factory for filesystem tools backed by league/flysystem.
 */
class FileStorage
{
    /**
     * Create all of the file storage tools for the given filesystem.
     *
     * @param \League\Flysystem\FilesystemOperator|string|null $filesystem Flysystem operator or absolute root path.
     * @return \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Tools\Filesystem\FilesystemTool>
     */
    public static function all(FilesystemOperator|string|null $filesystem = null): CollectionInterface
    {
        return static::readOnly($filesystem)->append([
            new WriteFile($filesystem),
            new DeleteFile($filesystem),
            new CopyFile($filesystem),
            new MoveFile($filesystem),
        ]);
    }

    /**
     * Create the read-only file storage tools for the given filesystem.
     *
     * @param \League\Flysystem\FilesystemOperator|string|null $filesystem Flysystem operator or absolute root path.
     * @return \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Tools\Filesystem\FilesystemTool>
     */
    public static function readOnly(FilesystemOperator|string|null $filesystem = null): CollectionInterface
    {
        return new Collection([
            new ListFiles($filesystem),
            new ReadFile($filesystem),
            new FileExists($filesystem),
            new GetFileMetadata($filesystem),
            new GetFileUrl($filesystem),
        ]);
    }
}
