<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Trait\PromptableTrait;

class DelegatingAgent implements Agent, HasTools
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a project manager that delegates research tasks to your research_agent sub-agent.';
    }

    public function tools(): iterable
    {
        return [
            new ResearchAgent(),
        ];
    }
}
