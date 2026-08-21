<?php
declare(strict_types=1);

/**
 * Delete a directory tree if it exists.
 *
 * @param string $directory Absolute directory path
 * @return void
 */
function removeDirectoryTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isDir()) {
            rmdir($fileInfo->getPathname());

            continue;
        }

        unlink($fileInfo->getPathname());
    }

    rmdir($directory);
}

/**
 * Clean generated Ai bake artifacts under TestApp.
 *
 * @return void
 */
function cleanAiBakeArtifacts(): void
{
    removeDirectoryTree(APP . 'Ai');
}

/**
 * Absolute path to a baked Ai class under TestApp.
 *
 * @param string $relativePath Path relative to TestApp/Ai
 * @return string
 */
function aiBakeClassPath(string $relativePath): string
{
    return APP . 'Ai' . DS . str_replace(['/', '\\'], DS, $relativePath);
}

/**
 * Absolute path to a plugin Ai bake template.
 *
 * @param string $theme Bake theme directory (e.g. Agent)
 * @param string $template Template basename without extension
 * @return string
 */
function aiBakeTemplatePath(string $theme, string $template): string
{
    return dirname(__DIR__, 2) . DS . 'templates' . DS . 'bake' . DS . $theme . DS . $template . '.twig';
}
