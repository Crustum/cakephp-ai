<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Closure;
use Crustum\Ai\Contracts\HasStructuredOutput;
use Crustum\JsonSchema\Contracts\JsonSchema;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Structured Anonymous Agent
 *
 * Ad-hoc agent with a structured output schema.
 */
class StructuredAnonymousAgent extends AnonymousAgent implements HasStructuredOutput
{
    public ?SerializableClosure $schema;

    /**
     * @param string $instructions System instructions
     * @param iterable<int, mixed> $messages Initial messages
     * @param iterable<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     * @param \Closure|null $schema Structured output schema builder
     */
    public function __construct(
        string $instructions,
        iterable $messages,
        iterable $tools,
        ?Closure $schema = null,
    ) {
        parent::__construct($instructions, $messages, $tools);

        $this->schema = $schema instanceof Closure ? new SerializableClosure($schema) : null;
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema The schema builder instance
     * @return array<string, \Crustum\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return call_user_func($this->schema, $schema);
    }
}
