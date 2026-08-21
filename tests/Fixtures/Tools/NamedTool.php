<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Tools;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;

class NamedTool implements Tool
{
    public function __construct(public readonly string $toolName = 'custom_named_tool')
    {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'A tool that declares its own name.';
    }

    public function handle(Request $request): string
    {
        return 'ok';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
