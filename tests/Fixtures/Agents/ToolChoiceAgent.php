<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Test\Fixtures\Tools\NamedTool;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Trait\PromptableTrait;

class ToolChoiceAgent implements Agent, HasTools
{
    use PromptableTrait;

    public function __construct(
        private ToolChoice|string|array|null $toolChoice = null,
    ) {
    }

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function toolChoice(): ToolChoice|string|array|null
    {
        return $this->toolChoice;
    }

    public function tools(): iterable
    {
        return [
            new RandomNumberGenerator(),
            new NamedTool('custom_named_tool'),
        ];
    }
}
