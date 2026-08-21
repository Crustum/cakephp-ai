<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Attributes\MaxSteps;
use Crustum\Ai\Attributes\MaxTokens;
use Crustum\Ai\Attributes\Temperature;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Trait\PromptableTrait;

#[MaxSteps(10)]
#[MaxTokens(4096)]
#[Temperature(0.7)]
class MethodOverridesAttributeAgent implements Agent
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function maxSteps(): int
    {
        return 1;
    }

    public function maxTokens(): int
    {
        return 512;
    }

    public function temperature(): float
    {
        return 0.2;
    }
}
