<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Trait\PromptableTrait;

class SecondaryAssistantAgent implements Agent
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant that responds extremely concisely to all queries.';
    }
}
