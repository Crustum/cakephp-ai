<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools\Filesystem;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;
use League\Flysystem\FilesystemException;

/**
 * Copy a file via league/flysystem.
 */
#[Strict]
class CopyFile extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Copy a file to a new path on the filesystem disk. The source file is left unchanged.';
    }

    /**
     * Execute the tool.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request.
     * @return string
     */
    public function handle(Request $request): string
    {
        $from = $this->normalize($request->string('from'));
        $to = $this->normalize($request->string('to'));
        $filesystem = $this->filesystem();

        if (!$this->fileExists($filesystem, $from)) {
            return sprintf('Unable to copy [%s] to [%s]. The source file may not exist.', $from, $to);
        }

        try {
            $filesystem->copy($from, $to);
        } catch (FilesystemException $filesystemException) {
            return sprintf('Unable to copy [%s] to [%s]: %s', $from, $to, $filesystemException->getMessage());
        }

        return sprintf('Copied [%s] to [%s].', $from, $to);
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
            'from' => $schema->string()
                ->description('The source file path, relative to the disk root.')
                ->required(),
            'to' => $schema->string()
                ->description('The destination file path, relative to the disk root.')
                ->required(),
        ];
    }
}
