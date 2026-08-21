<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasStructuredOutput;
use Crustum\Ai\Trait\PromptableTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;

/**
 * Agent with constrained structured output schema.
 */
class ConstrainedStructuredAgent implements Agent, HasStructuredOutput
{
    use PromptableTrait;

    /**
     * Get the instructions that the agent should follow.
     *
     * @return string
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that uses structured output.';
    }

    /**
     * Get the structured output's schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema The schema builder instance
     * @return array<string, \Crustum\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->required()->min(1)->max(10),
            'summary' => $schema->string()->required()->min(1)->max(280),
            'tags' => $schema->array()->required()->items($schema->string()->max(20))->min(1)->max(5),
        ];
    }
}
