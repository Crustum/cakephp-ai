<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Trait\PromptableTrait;

/**
 * Anonymous Agent
 *
 * Ad-hoc agent with instructions, messages, and tools.
 */
class AnonymousAgent implements Agent, Conversational, HasTools
{
    use PromptableTrait;

    /**
     * @param string $instructions System instructions
     * @param iterable<int, mixed> $messages Initial messages
     * @param iterable<int, \Crustum\Ai\Contracts\Tool> $tools Available tools
     */
    public function __construct(
        public string $instructions,
        public iterable $messages,
        public iterable $tools,
    ) {
    }

    /**
     * Get the agent instructions.
     *
     * @return string
     */
    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * Get the agent messages.
     *
     * @return iterable<int, mixed>
     */
    public function messages(): iterable
    {
        return $this->messages;
    }

    /**
     * Get the agent tools.
     *
     * @return iterable<int, \Crustum\Ai\Contracts\Tool>
     */
    public function tools(): iterable
    {
        return $this->tools;
    }
}
