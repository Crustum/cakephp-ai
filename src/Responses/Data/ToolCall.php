<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;

/**
 * Tool Call
 *
 * Represents a tool invocation requested by an AI model.
 */
class ToolCall implements JsonSerializable
{
    /**
     * Constructor
     *
     * @param string $id The unique identifier for this tool call.
     * @param string $name The name of the tool to invoke.
     * @param array<string, mixed> $arguments The arguments to pass to the tool.
     * @param string|null $resultId The ID linking this call to its result.
     * @param string|null $reasoningId The ID of the reasoning block.
     * @param array<string, mixed>|null $reasoningSummary Summary of the reasoning process.
     * @param string|null $reasoningEncryptedContent Encrypted reasoning content.
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public ?string $resultId = null,
        public ?string $reasoningId = null,
        public ?array $reasoningSummary = null,
        public ?string $reasoningEncryptedContent = null,
    ) {
    }

    /**
     * Reconstruct an instance from a previously serialized toArray() payload.
     *
     * @param array<string, mixed> $data The serialized data.
     * @return self The reconstructed instance.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            arguments: $data['arguments'],
            resultId: $data['result_id'] ?? null,
            reasoningId: $data['reasoning_id'] ?? null,
            reasoningSummary: $data['reasoning_summary'] ?? null,
            reasoningEncryptedContent: $data['reasoning_encrypted_content'] ?? null,
        );
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
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result_id' => $this->resultId,
            'reasoning_id' => $this->reasoningId,
            'reasoning_summary' => $this->reasoningSummary,
            'reasoning_encrypted_content' => $this->reasoningEncryptedContent,
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
