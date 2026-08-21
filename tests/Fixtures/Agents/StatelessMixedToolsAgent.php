<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Test\Fixtures\Tools\ApprovableNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\SideEffectRecorder;
use Crustum\Ai\Trait\PromptableTrait;

class StatelessMixedToolsAgent implements Agent, HasTools
{
    use PromptableTrait;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You record side effects and generate numbers on request.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return iterable<int, \Crustum\Ai\Contracts\Tool>
     */
    public function tools(): iterable
    {
        return [new SideEffectRecorder(), new ApprovableNumberGenerator()];
    }
}
