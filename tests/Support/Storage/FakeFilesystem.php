<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Storage;

use Crustum\Ai\Filesystem\LocalFlysystem;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Assert;

/**
 * Assertion helpers around the configured Ai.filesystem.root Flysystem.
 */
class FakeFilesystem
{
    /**
     * Resolve the Flysystem operator under test.
     *
     * @return \League\Flysystem\FilesystemOperator
     */
    protected function operator(): FilesystemOperator
    {
        return LocalFlysystem::fromConfig();
    }

    /**
     * Write a file.
     *
     * @param string $path Relative path.
     * @param string $contents File contents.
     * @return void
     */
    public function put(string $path, string $contents): void
    {
        $this->operator()->write(ltrim(str_replace('\\', '/', $path), '/'), $contents);
    }

    /**
     * Read a file.
     *
     * @param string $path Relative path.
     * @return string
     */
    public function get(string $path): string
    {
        return $this->operator()->read(ltrim(str_replace('\\', '/', $path), '/'));
    }

    /**
     * Create a directory.
     *
     * @param string $path Relative directory path.
     * @return void
     */
    public function makeDirectory(string $path): void
    {
        $this->operator()->createDirectory(ltrim(str_replace('\\', '/', $path), '/'));
    }

    /**
     * Assert paths exist (file or directory).
     *
     * @param string|array<int, string> $paths Relative paths.
     * @return void
     */
    public function assertExists(string|array $paths): void
    {
        $operator = $this->operator();

        foreach ((array)$paths as $path) {
            $normalized = ltrim(str_replace('\\', '/', $path), '/');
            Assert::assertTrue(
                $operator->fileExists($normalized) || $operator->directoryExists($normalized),
                sprintf('Failed asserting that path [%s] exists.', $path),
            );
        }
    }

    /**
     * Assert files are missing.
     *
     * @param string|array<int, string> $paths Relative file paths.
     * @return void
     */
    public function assertMissing(string|array $paths): void
    {
        $operator = $this->operator();

        foreach ((array)$paths as $path) {
            $normalized = ltrim(str_replace('\\', '/', $path), '/');
            Assert::assertFalse(
                $operator->fileExists($normalized),
                sprintf('Failed asserting that file [%s] is missing.', $path),
            );
        }
    }

    /**
     * Write a file into a directory with a given name.
     *
     * @param string $directory Directory path.
     * @param string $contents File contents.
     * @param string $name File name.
     * @return void
     */
    public function putFileAs(string $directory, string $contents, string $name): void
    {
        $path = trim($directory, '/') . '/' . ltrim($name, '/');
        $this->put($path, $contents);
    }

    /**
     * Assert the number of files in a directory.
     *
     * @param string $directory Directory path.
     * @param int $count Expected file count.
     * @return void
     */
    public function assertCount(string $directory, int $count): void
    {
        $normalized = ltrim(str_replace('\\', '/', $directory), '/');
        $files = [];

        foreach ($this->operator()->listContents($normalized, false) as $item) {
            if ($item->isFile()) {
                $files[] = $item->path();
            }
        }

        Assert::assertCount($count, $files, sprintf('Failed asserting directory [%s] has %d files.', $directory, $count));
    }

    /**
     * Assert that a directory contains no files.
     *
     * @param string $directory Directory path.
     * @return void
     */
    public function assertDirectoryEmpty(string $directory): void
    {
        $this->assertCount($directory, 0);
    }
}
