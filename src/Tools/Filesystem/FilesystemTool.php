<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools\Filesystem;

use Cake\Core\Configure;
use Crustum\Ai\Approvals\Approval;
use Crustum\Ai\Contracts\Approvable;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Filesystem\LocalFlysystem;
use Crustum\Ai\Tools\Request;
use Crustum\Ai\Trait\InteractsWithApprovalsTrait;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Base class for filesystem tools backed by league/flysystem.
 */
abstract class FilesystemTool implements Approvable, Tool
{
    use InteractsWithApprovalsTrait;

    /**
     * @param \League\Flysystem\FilesystemOperator|string|null $filesystem Flysystem operator or absolute root path.
     */
    public function __construct(protected FilesystemOperator|string|null $filesystem = null)
    {
    }

    /**
     * Determine whether the given path points to a file, not a directory.
     *
     * @param \League\Flysystem\FilesystemOperator $filesystem Flysystem operator.
     * @param string $path Relative file path.
     * @return bool
     */
    protected function fileExists(FilesystemOperator $filesystem, string $path): bool
    {
        try {
            return $filesystem->fileExists($this->normalize($path));
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * Resolve the Flysystem operator the tool operates on.
     *
     * @return \League\Flysystem\FilesystemOperator
     */
    protected function filesystem(): FilesystemOperator
    {
        if ($this->filesystem instanceof FilesystemOperator) {
            return $this->filesystem;
        }

        if (is_string($this->filesystem) && $this->filesystem !== '') {
            return LocalFlysystem::create($this->filesystem);
        }

        return LocalFlysystem::fromConfig();
    }

    /**
     * Build a public URL for a path (Flysystem has no URL API).
     *
     * @param string $path Relative file path.
     * @param int|null $expiresInMinutes Optional expiry hint appended as a query param.
     * @return string
     */
    protected function publicUrl(string $path, ?int $expiresInMinutes = null): string
    {
        $baseUrl = Configure::read('Ai.filesystem.url', '/storage');
        $url = rtrim((string)$baseUrl, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');

        if ($expiresInMinutes !== null && $expiresInMinutes > 0) {
            $url .= '?expires=' . now()->addMinutes($expiresInMinutes)->getTimestamp();
        }

        return $url;
    }

    /**
     * List directory or file paths under a location.
     *
     * @param \League\Flysystem\FilesystemOperator $filesystem Flysystem operator.
     * @param string $directory Relative directory path.
     * @param bool $directories Whether to list directories instead of files.
     * @param bool $recursive Whether to recurse.
     * @return array<int, string>
     */
    protected function listPaths(
        FilesystemOperator $filesystem,
        string $directory,
        bool $directories,
        bool $recursive,
    ): array {
        try {
            $listing = $filesystem->listContents($this->normalize($directory), $recursive);
        } catch (FilesystemException) {
            return [];
        }

        $entries = [];

        /** @var \League\Flysystem\StorageAttributes $item */
        foreach ($listing as $item) {
            if ($directories && $item->isDir()) {
                $entries[] = $item->path();
            }

            if (!$directories && $item->isFile()) {
                $entries[] = $item->path();
            }
        }

        sort($entries);

        return $entries;
    }

    /**
     * Normalize a relative path for Flysystem.
     *
     * @param string $path Relative path.
     * @return string
     */
    protected function normalize(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Determine whether the tool needs approval for the given request.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request
     * @return \Crustum\Ai\Approvals\Approval|bool
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
