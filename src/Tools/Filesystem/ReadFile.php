<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools\Filesystem;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;
use League\Flysystem\FilesystemException;
use Throwable;

/**
 * Read UTF-8 text file contents via league/flysystem.
 */
#[Strict]
class ReadFile extends FilesystemTool
{
    /**
     * The maximum number of bytes that may be read inline.
     */
    protected const MAX_BYTES = 256 * 1024;

    /**
     * Get the description of the tool's purpose.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Read and return the UTF-8 text contents of a file on the filesystem disk. Files larger than 256 KB or that are not valid UTF-8 text (such as images or other binary files) are rejected; use GetFileUrl to access those instead.';
    }

    /**
     * Execute the tool.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request.
     * @return string
     */
    public function handle(Request $request): string
    {
        $filesystem = $this->filesystem();
        $path = $this->normalize($request->string('path'));

        try {
            $size = $filesystem->fileSize($path);
        } catch (Throwable) {
            return sprintf('File [%s] does not exist.', $path);
        }

        if ($size > static::MAX_BYTES) {
            return sprintf('File [%s] is too large to read inline. Use GetFileMetadata or GetFileUrl instead.', $path);
        }

        try {
            $contents = $filesystem->read($path);
        } catch (FilesystemException) {
            return sprintf('File [%s] does not exist.', $path);
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            return sprintf('File [%s] appears to be binary and cannot be read as text. Use GetFileUrl to access it.', $path);
        }

        return $contents;
    }

    /**
     * Get the tool's schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema Schema builder.
     * @return array<string, \Crustum\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path to read, relative to the disk root.')
                ->required(),
        ];
    }
}
