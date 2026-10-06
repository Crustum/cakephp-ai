<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\SecretCodeGenerator;
use Crustum\Ai\Trait\PromptableTrait;

class ToolSearchAgent implements Agent, HasTools
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are an assistant with access to tools. Some tools are not loaded '
            . 'upfront and must be discovered using your tool search capability before '
            . 'they can be called. Always use the appropriate tool to answer; never guess. '
            . 'When asked for the secret authorization code, find and call the tool that returns it.';
    }

    public function tools(): iterable
    {
        return [
            new FixedNumberGenerator(),
            new ToolSearch(tools: [new SecretCodeGenerator()]),
        ];
    }
}
