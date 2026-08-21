<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Attributes\WithoutBroadcasting;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Trait\PromptableTrait;

#[WithoutBroadcasting(TextDelta::class)]
class NonBroadcastingTextAgent implements Agent
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
