<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;

/**
 * Tool execution result data.
 *
 * Represents the result of executing a tool, including the
 * tool's output and any associated metadata.
 */
class ToolResult implements JsonSerializable
{
    /**
     * Constructor.
     *
     * @param string $id Tool call identifier
     * @param string $name Tool name
     * @param array<string, mixed> $arguments Tool arguments
     * @param mixed $result Tool execution result
     * @param string|null $resultId Optional result identifier
     * @param bool $denied Whether the tool call was denied / not approved
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public mixed $result,
        public ?string $resultId = null,
        public bool $denied = false,
    ) {
    }

    /**
     * Reconstruct an instance from a previously serialized toArray() payload.
     *
     * @param array<string, mixed> $data Serialized data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            arguments: $data['arguments'],
            result: $data['result'],
            resultId: $data['result_id'] ?? null,
            denied: $data['denied'] ?? false,
        );
    }

    /**
     * Get the instance as an array, only including the denied key when the result is a rejection.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result' => $this->result,
            'result_id' => $this->resultId,
            ...($this->denied ? ['denied' => true] : []),
        ];
    }

    /**
     * Get the JSON serializable representation.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
