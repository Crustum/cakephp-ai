<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\HasProviderId;
use Crustum\Ai\Files\Trait\CanBeRetrievedOrDeletedFromProviderTrait;
use JsonSerializable;

/**
 * Provider Image
 *
 * Represents an image stored with an AI provider using a provider-specific ID.
 */
class ProviderImage extends Image implements HasProviderId, JsonSerializable
{
    use CanBeRetrievedOrDeletedFromProviderTrait;

    /**
     * Constructor
     *
     * @param string $id The provider-specific image ID.
     */
    public function __construct(
        public readonly string $id,
    ) {
    }

    /**
     * Get the provider ID for the stored file.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'provider-image',
            'id' => $this->id,
            'name' => $this->name(),
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
