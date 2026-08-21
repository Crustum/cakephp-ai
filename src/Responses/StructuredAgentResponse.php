<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use ArrayAccess;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\Trait\ProvidesStructuredResponseTrait;
use JsonSerializable;
use Override;

/**
 * Structured agent response.
 *
 * Agent response with structured output that conforms to a schema.
 * Implements ArrayAccess for convenient data access.
 */
class StructuredAgentResponse extends AgentResponse implements ArrayAccess, JsonSerializable
{
    use ProvidesStructuredResponseTrait;

    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param array<string, mixed> $structured Structured output data
     * @param string $text Text representation
     * @param \Crustum\Ai\Responses\Data\Usage $usage Token usage
     * @param \Crustum\Ai\Responses\Data\Meta $meta Response metadata
     */
    public function __construct(string $invocationId, array $structured, string $text, Usage $usage, Meta $meta)
    {
        parent::__construct($invocationId, $text, $usage, $meta);

        $this->structured = $structured;
        $this->toolCalls = collection([]);
        $this->toolResults = collection([]);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->structured;
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param int $options JSON encode options
     * @return string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->structured, $options);
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

    /**
     * Get the string representation of the object.
     *
     * @return string
     */
    #[Override]
    public function __toString(): string
    {
        return (string)json_encode($this->structured);
    }
}
