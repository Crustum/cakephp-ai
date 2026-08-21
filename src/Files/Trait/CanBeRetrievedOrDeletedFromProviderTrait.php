<?php
declare(strict_types=1);

namespace Crustum\Ai\Files\Trait;

use Crustum\Ai\Files;
use Crustum\Ai\Responses\FileResponse;

/**
 * Can be retrieved or deleted from provider trait.
 *
 * Provides file retrieval and deletion functionality for provider-stored files.
 */
trait CanBeRetrievedOrDeletedFromProviderTrait
{
    /**
     * Retrieve the file from a given provider.
     *
     * @param string|null $provider Provider name
     * @return \Crustum\Ai\Responses\FileResponse
     */
    public function get(?string $provider = null): FileResponse
    {
        return Files::get($this->id, provider: $provider);
    }

    /**
     * Delete the file from a given provider.
     *
     * @param string|null $provider Provider name
     * @return void
     */
    public function delete(?string $provider = null): void
    {
        Files::delete($this->id, provider: $provider);
    }
}
