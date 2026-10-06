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
     * @param bool $failed Whether the tool call failed to run
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public mixed $result,
        public ?string $resultId = null,
        public bool $denied = false,
        public bool $failed = false,
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
            arguments: $data['arguments'] ?? [],
            result: $data['result'],
            resultId: $data['result_id'] ?? null,
            denied: $data['denied'] ?? false,
            failed: $data['failed'] ?? false,
        );
    }

    /**
     * Determine if the tool call ran and produced its own result.
     *
     * @return bool
     */
    public function successful(): bool
    {
        return !$this->denied && !$this->failed;
    }

    /**
     * Get the message explaining why the tool call did not succeed.
     *
     * @return string|null
     */
    public function error(): ?string
    {
        return $this->successful() || !is_string($this->result) ? null : $this->result;
    }

    /**
     * Get the result as a string suitable for sending back to a provider.
     *
     * @return string
     */
    public function text(): string
    {
        return match (true) {
            is_string($this->result) => $this->result,
            is_array($this->result) => (string)json_encode($this->result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => strval($this->result),
        };
    }

    /**
     * Get the instance as an array, only including the denied and failed keys when they apply.
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
            ...($this->failed ? ['failed' => true] : []),
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
