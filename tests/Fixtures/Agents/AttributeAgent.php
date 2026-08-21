<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Attributes\MaxSteps;
use Crustum\Ai\Attributes\MaxTokens;
use Crustum\Ai\Attributes\Provider;
use Crustum\Ai\Attributes\Temperature;
use Crustum\Ai\Attributes\TopP;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Trait\PromptableTrait;

#[MaxSteps(10)]
#[MaxTokens(4096)]
#[Temperature(0.7)]
#[TopP(0.8)]
#[Provider('anthropic')]
class AttributeAgent implements Agent
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
