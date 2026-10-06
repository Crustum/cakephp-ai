<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Files\File;

/**
 * Prepares Storable Files Trait
 *
 * Prepares file payloads and provider options for gateway uploads.
 */
trait PreparesStorableFilesTrait
{
    /**
     * Prepare file data for upload.
     *
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file File to upload
     * @return array{0: string, 1: string, 2: string}
     */
    protected function prepareStorableFile(StorableFile $file): array
    {
        return [
            $file->content(),
            $file->mimeType() ?? 'application/octet-stream',
            $file->name() ?? 'file',
        ];
    }

    /**
     * Resolve the upload body options and HTTP headers for the given file.
     *
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file File to upload
     * @param \Crustum\Ai\Enums\Lab|string $provider Provider identifier
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    protected function resolveProviderOptionsAndHeaders(StorableFile $file, Lab|string $provider): array
    {
        return [
            $file instanceof HasProviderOptions ? $file->providerOptions($provider) : [],
            $file instanceof File ? $file->headers($provider) : [],
        ];
    }
}
