<?php
declare(strict_types=1);

namespace Crustum\Ai\Files\Trait;

use Crustum\Ai\Files;
use Crustum\Ai\Responses\StoredFileResponse;

/**
 * Can be uploaded to provider trait.
 *
 * Provides file upload functionality for storable files.
 */
trait CanBeUploadedToProviderTrait
{
    /**
     * Store the file on a given provider.
     *
     * @param string|null $mimeType MIME type
     * @param string|null $name File name
     * @param string|null $provider Provider name
     * @return \Crustum\Ai\Responses\StoredFileResponse
     */
    public function put(?string $mimeType = null, ?string $name = null, ?string $provider = null): StoredFileResponse
    {
        return Files::put(
            $this,
            mimeType: $mimeType ?? $this->mimeType() ?? null,
            name: $name ?? $this->name() ?? null,
            provider: $provider,
        );
    }
}
