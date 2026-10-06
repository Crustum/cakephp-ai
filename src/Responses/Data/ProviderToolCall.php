<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;

/**
 * Provider Tool Call
 *
 * Represents a provider-hosted tool invocation recorded on a step,
 * such as a web search or code execution call the provider ran itself.
 */
class ProviderToolCall implements JsonSerializable
{
    /**
     * Constructor
     *
     * @param string $id The provider-issued identifier for this call.
     * @param string $type The provider tool type.
     * @param array<string, mixed> $data The raw provider payload.
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $data,
    ) {
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed> The array representation.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     *
     * @return array<string, mixed> The JSON serializable data.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
