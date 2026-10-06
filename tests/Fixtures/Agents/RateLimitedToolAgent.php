<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Test\Fixtures\Tools\RateLimitedNumberGenerator;
use Crustum\Ai\Trait\PromptableTrait;

class RateLimitedToolAgent implements Agent, HasTools
{
    use PromptableTrait;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers using the tool available to you.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [new RateLimitedNumberGenerator()];
    }
}
