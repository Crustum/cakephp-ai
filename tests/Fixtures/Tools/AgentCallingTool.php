<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Tools;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Test\Fixtures\Agents\ResearchAgent;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;

class AgentCallingTool implements Tool
{
    public function description(): string
    {
        return 'Delegates to a research agent from within the tool handler.';
    }

    public function handle(Request $request): string
    {
        return ResearchAgent::make()->prompt('Research CakePHP')->text;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
