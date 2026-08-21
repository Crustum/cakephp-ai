<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools\Filesystem;

use Crustum\Ai\Attributes\Strict;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;
use Throwable;

/**
 * Generate a public URL for a file (config-based; Flysystem has no URL API).
 */
#[Strict]
class GetFileUrl extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Generate a URL for accessing a file on the filesystem disk. Disks that do not support URLs return an explanatory message instead of a URL.';
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

        if (!$this->fileExists($this->filesystem(), $path)) {
            return sprintf('File [%s] does not exist.', $path);
        }

        $minutes = $request->integer('expires_in_minutes');

        try {
            return $this->publicUrl($path, $minutes > 0 ? $minutes : null);
        } catch (Throwable $throwable) {
            return sprintf('Unable to generate a URL for [%s]: %s', $path, $throwable->getMessage());
        }
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
                ->description('The file path, relative to the disk root.')
                ->required(),
            'expires_in_minutes' => $schema->integer()
                ->description("Number of minutes a temporary signed URL stays valid, or null for the disk's standard (non-expiring) URL.")
                ->nullable()
                ->required(),
        ];
    }
}
