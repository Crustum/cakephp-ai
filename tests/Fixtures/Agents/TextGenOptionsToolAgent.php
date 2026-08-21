<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Attributes\MaxTokens;
use Crustum\Ai\Attributes\Temperature;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Trait\PromptableTrait;

#[Temperature(0.2)]
#[MaxTokens(1024)]
class TextGenOptionsToolAgent implements Agent, HasTools
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [new FixedNumberGenerator()];
    }
}
