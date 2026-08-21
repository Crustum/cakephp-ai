<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Files;

/**
 * Interface for files that have a provider-specific identifier.
 *
 * This interface marks files that have been stored with an AI provider
 * and have received a provider-specific ID for retrieval and reference.
 */
interface HasProviderId
{
    /**
     * Get the provider ID for the stored file.
     *
     * @return string The unique identifier assigned by the provider
     */
    public function id(): string;
}
