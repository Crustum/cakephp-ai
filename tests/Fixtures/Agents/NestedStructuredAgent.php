<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasStructuredOutput;
use Crustum\Ai\Trait\PromptableTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;

class NestedStructuredAgent implements Agent, HasStructuredOutput
{
    use PromptableTrait;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that knows about periodic table elements.';
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'elements' => $schema->array()->required()->items(
                $schema->object([
                    'atomicNumber' => $schema->integer()->required(),
                    'symbol' => $schema->string()->required(),
                ]),
            ),
        ];
    }
}
