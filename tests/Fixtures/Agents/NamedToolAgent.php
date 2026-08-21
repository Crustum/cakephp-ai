<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Test\Fixtures\Tools\NamedTool;
use Crustum\Ai\Trait\PromptableTrait;

class NamedToolAgent implements Agent, HasTools
{
    use PromptableTrait;

    public function __construct(public readonly string $toolName = 'custom_named_tool')
    {
    }

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [
            new NamedTool($this->toolName),
        ];
    }
}
