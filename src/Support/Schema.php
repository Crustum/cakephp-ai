<?php
declare(strict_types=1);

namespace Crustum\Ai\Support;

use Crustum\Ai\Contracts\Schemable;
use Crustum\JsonSchema\Types\Type;

/**
 * Schema
 *
 * Represents a structured output schema definition.
 */
class Schema implements Schemable
{
    /**
     * Create a new output schema.
     *
     * @param \Crustum\JsonSchema\Types\Type $schema The schema type
     * @param string $name The schema name
     * @param bool $strict Whether strict mode is enabled
     */
    public function __construct(
        public Type $schema,
        public string $name = 'schema_definition',
        public bool $strict = false,
    ) {
    }

    /**
     * Get the name of the schema.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Create a new output schema with the given name.
     *
     * @param string $name The schema name
     */
    public function withName(string $name): self
    {
        return new self(
            $this->schema,
            $name,
            $this->strict,
        );
    }

    /**
     * Get the array representation of the schema.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toSchema();
    }

    /**
     * Get the array representation of the schema.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return $this->schema->toArray();
    }
}
