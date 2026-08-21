<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\CanActAsTool;
use Crustum\Ai\Trait\PromptableTrait;

class ResearchAgent implements Agent, CanActAsTool
{
    use PromptableTrait;

    public function name(): string
    {
        return 'research_agent';
    }

    public function description(): string
    {
        return 'Research a topic in depth and return a summary.';
    }

    public function instructions(): string
    {
        return 'You are a research agent. Summarize your findings concisely.';
    }
}
