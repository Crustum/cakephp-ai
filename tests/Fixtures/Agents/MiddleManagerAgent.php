<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\CanActAsTool;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Trait\PromptableTrait;

class MiddleManagerAgent implements Agent, CanActAsTool, HasTools
{
    use PromptableTrait;

    public function name(): string
    {
        return 'middle_manager';
    }

    public function description(): string
    {
        return 'Delegate specialized research tasks to the research agent.';
    }

    public function instructions(): string
    {
        return 'You are a middle manager that breaks down tasks and delegates to the research_agent sub-agent.';
    }

    public function tools(): iterable
    {
        return [
            new ResearchAgent(),
        ];
    }
}
