<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Crustum\Ai\Contracts\Files\HasProviderId;
use Crustum\Ai\Files\Trait\CanBeRetrievedOrDeletedFromProviderTrait;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Provider document.
 *
 * Represents a document stored on an AI provider's platform.
 */
class ProviderDocument extends Document implements HasProviderId, JsonSerializable
{
    use CanBeRetrievedOrDeletedFromProviderTrait;

    /**
     * Constructor.
     *
     * @param string $id Provider document ID
     */
    public function __construct(public string $id)
    {
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
     * Get the raw representation of the file.
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public function content(): string
    {
        throw new InvalidArgumentException(
            'ProviderDocument cannot be read directly. It is a reference to a file stored on an external provider.',
        );
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'provider-document',
            'id' => $this->id,
            'name' => $this->name(),
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
