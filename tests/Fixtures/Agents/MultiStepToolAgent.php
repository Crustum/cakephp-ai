<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Attributes\MaxSteps;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Trait\PromptableTrait;

#[MaxSteps(10)]
class MultiStepToolAgent implements Agent, HasTools
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers.';
    }

    public function tools(): iterable
    {
        return [new FixedNumberGenerator()];
    }
}
