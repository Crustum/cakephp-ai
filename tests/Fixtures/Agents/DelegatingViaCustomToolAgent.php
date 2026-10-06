<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Test\Fixtures\Tools\AgentCallingTool;
use Crustum\Ai\Trait\PromptableTrait;

class DelegatingViaCustomToolAgent implements Agent, HasTools
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You delegate research using your tool.';
    }

    public function tools(): iterable
    {
        return [
            new AgentCallingTool(),
        ];
    }
}
