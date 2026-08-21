<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\CanActAsTool;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Trait\PromptableTrait;

class OrchestratorAgent implements Agent, CanActAsTool, HasTools
{
    use PromptableTrait;

    public function name(): string
    {
        return 'orchestrator';
    }

    public function description(): string
    {
        return 'Orchestrate complex tasks by delegating to specialized sub-agents.';
    }

    public function instructions(): string
    {
        return 'You are an orchestrator that breaks down complex tasks and delegates to the middle_manager sub-agent.';
    }

    public function tools(): iterable
    {
        return [
            new MiddleManagerAgent(),
        ];
    }
}
