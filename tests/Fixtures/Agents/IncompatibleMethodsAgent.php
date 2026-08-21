<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Attributes\MaxSteps;
use Crustum\Ai\Attributes\MaxTokens;
use Crustum\Ai\Attributes\Temperature;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Trait\PromptableTrait;

#[MaxSteps(8)]
#[MaxTokens(1024)]
#[Temperature(0.9)]
class IncompatibleMethodsAgent implements Agent
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function maxSteps(int $scale): int
    {
        return $scale * 2;
    }

    protected function maxTokens(): int
    {
        return 256;
    }
}
