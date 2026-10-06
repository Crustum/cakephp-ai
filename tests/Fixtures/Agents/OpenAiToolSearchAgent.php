<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Attributes\Model;
use Crustum\Ai\Attributes\Provider;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Test\Fixtures\Tools\DeferredTool;
use Crustum\Ai\Test\Fixtures\Tools\NonStrictTool;
use Crustum\Ai\Trait\PromptableTrait;

#[Provider('openai')]
#[Model('gpt-5.4')]
class OpenAiToolSearchAgent implements Agent, HasTools
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [new NonStrictTool(), new ToolSearch(tools: [new DeferredTool()])];
    }
}
