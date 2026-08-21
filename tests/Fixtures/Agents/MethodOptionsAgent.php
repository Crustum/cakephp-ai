<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Trait\PromptableTrait;

class MethodOptionsAgent implements Agent
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function maxSteps(): int
    {
        return 3;
    }

    public function maxTokens(): int
    {
        return 2048;
    }

    public function temperature(): float
    {
        return 0.5;
    }
}
