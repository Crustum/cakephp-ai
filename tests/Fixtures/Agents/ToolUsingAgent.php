<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasStructuredOutput;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Trait\PromptableTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;

class ToolUsingAgent implements Agent, HasStructuredOutput, HasTools
{
    use PromptableTrait;

    public function __construct(public bool $fixed = false, public bool $toolThrowsException = false)
    {
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that uses structured output and generates random numbers using the tool available to you. Always use the tool to get a cryptographically secure random number.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            $this->fixed
                ? new FixedNumberGenerator($this->toolThrowsException)
                : new RandomNumberGenerator($this->toolThrowsException),
        ];
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->required(),
        ];
    }
}
