<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools\Filesystem;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;

/**
 * List files and directories via league/flysystem.
 */
#[Strict]
class ListFiles extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     *
     * @return string
     */
    public function description(): string
    {
        return 'List the files and directories directly within a path on the filesystem disk. Returns a JSON object with separate "directories" and "files" arrays of paths.';
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
        $path = $request->string('path');
        $recursive = $request->boolean('recursive');

        return json_encode([
            'path' => $path,
            'directories' => $this->listPaths($filesystem, $path, directories: true, recursive: $recursive),
            'files' => $this->listPaths($filesystem, $path, directories: false, recursive: $recursive),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
                ->description('The directory path to list, relative to the disk root. Use an empty string to list the disk root.')
                ->required(),
            'recursive' => $schema->boolean()
                ->description('When true, also include files and directories within all nested subdirectories.')
                ->required(),
        ];
    }
}
