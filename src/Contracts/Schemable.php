<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

/**
 * Schemable Interface
 *
 * Defines the contract for objects that can be represented as a schema definition.
 * This is used for structured outputs and tool parameters.
 */
interface Schemable
{
    /**
     * Get the name of the schema.
     *
     * Returns a unique identifier for this schema. This name is used to reference
     * the schema in structured outputs or tool definitions.
     *
     * @return string The schema's name.
     */
    public function name(): string;

    /**
     * Get the array representation of the schema.
     *
     * Returns the schema as an associative array defining its structure, properties,
     * required fields, and validation rules.
     *
     * @return array<string, mixed> The schema definition.
     */
    public function toSchema(): array;
}
