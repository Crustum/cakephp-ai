<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Contracts\RemembersConversations;
use Crustum\Ai\Test\Fixtures\Tools\ApprovableNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Trait\PromptableTrait;
use Crustum\Ai\Trait\RemembersConversationsTrait;

class RememberingMultiStepApprovableAgent implements Agent, HasTools, RemembersConversations
{
    use PromptableTrait;
    use RemembersConversationsTrait;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that generates a number, then generates a gated number.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return iterable<int, \Crustum\Ai\Contracts\Tool>
     */
    public function tools(): iterable
    {
        return [new FixedNumberGenerator(), new ApprovableNumberGenerator()];
    }
}
