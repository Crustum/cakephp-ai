<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools\Filesystem;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;
use League\Flysystem\FilesystemException;

/**
 * Delete a file via league/flysystem.
 */
#[Strict]
class DeleteFile extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Permanently delete a file from the filesystem disk. This cannot be undone.';
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

        if (!$this->fileExists($filesystem, $path)) {
            return sprintf('File [%s] does not exist.', $path);
        }

        try {
            $filesystem->delete($path);
        } catch (FilesystemException) {
            return sprintf('Unable to delete [%s].', $path);
        }

        return sprintf('Deleted [%s].', $path);
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
                ->description('The file path to delete, relative to the disk root.')
                ->required(),
        ];
    }
}
