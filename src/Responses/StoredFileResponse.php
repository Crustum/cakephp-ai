<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Crustum\Ai\Contracts\Files\HasProviderId;

/**
 * Stored File Response
 *
 * Represents a file that has been successfully stored with an AI provider.
 */
class StoredFileResponse implements HasProviderId
{
    /**
     * Constructor
     *
     * @param string $id The provider-assigned file ID.
     */
    public function __construct(
        public readonly string $id,
    ) {
    }

    /**
     * Get the provider ID for the stored file.
     *
     * @return string The file ID.
     */
    public function id(): string
    {
        return $this->id;
    }
}
