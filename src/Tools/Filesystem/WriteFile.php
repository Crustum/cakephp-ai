<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools\Filesystem;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;
use League\Flysystem\FilesystemException;

/**
 * Write UTF-8 text contents via league/flysystem.
 */
#[Strict]
class WriteFile extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Write UTF-8 text contents to a file on the filesystem disk, creating it (and any parent directories) or overwriting it if it already exists.';
    }

    /**
     * Execute the tool.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request.
     * @return string
     */
    public function handle(Request $request): string
    {
        $path = $this->normalize($request->string('path'));
        $contents = $request->string('contents');

        try {
            $this->filesystem()->write($path, $contents);
        } catch (FilesystemException) {
            return sprintf('Unable to write [%s].', $path);
        }

        return 'Wrote ' . strlen($contents) . sprintf(' bytes to [%s].', $path);
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
                ->description('The file path to write to, relative to the disk root.')
                ->required(),
            'contents' => $schema->string()
                ->description('The UTF-8 text contents to write to the file.')
                ->required(),
        ];
    }
}
