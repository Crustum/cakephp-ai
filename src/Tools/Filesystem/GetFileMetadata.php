<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools\Filesystem;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;
use Throwable;

/**
 * Get file metadata via league/flysystem.
 */
#[Strict]
class GetFileMetadata extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Get metadata for a file (size in bytes, last modified time, MIME type, visibility) without reading its contents. Use ReadFile to read the contents.';
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

        return json_encode([
            'path' => $path,
            'size' => $size,
            'last_modified' => $this->attempt(fn(): int => $filesystem->lastModified($path)),
            'mime_type' => $this->attempt(fn(): string => $filesystem->mimeType($path)),
            'visibility' => $this->attempt(fn(): string => $filesystem->visibility($path)),
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
                ->description('The file path to inspect, relative to the disk root.')
                ->required(),
        ];
    }

    /**
     * Attempt a callback and return null on failure.
     *
     * @param callable(): mixed $callback Callback to execute.
     */
    protected function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
