<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

use Crustum\JsonSchema\Contracts\JsonSchema;

/**
 * HasStructuredOutput Interface
 *
 * Allows agents to define a structured output schema, forcing the AI provider to return
 * responses in a specific JSON format that matches the schema definition.
 */
interface HasStructuredOutput
{
    /**
     * Get the agent's structured output schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema The schema builder instance
     * @return array<string, \Crustum\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array;
}
