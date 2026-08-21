<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Crustum\Ai\Contracts\Files\HasProviderId;

/**
 * Added Document Response
 *
 * Represents a response when a document is added to a vector store.
 */
class AddedDocumentResponse implements HasProviderId
{
    /**
     * Constructor
     *
     * @param string $id The provider document ID
     * @param string|null $fileId The provider file ID (if applicable)
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $fileId = null,
    ) {
    }

    /**
     * Get the provider document ID for the file that was added to the vector store.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the provider ID for the file that was stored for later reference, if applicable.
     */
    public function fileId(): ?string
    {
        return $this->fileId;
    }
}
