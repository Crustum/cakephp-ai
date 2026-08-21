<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Tools;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;

class ProtectedNameTool implements Tool
{
    protected function name(): string
    {
        return 'should_not_be_used';
    }

    public function description(): string
    {
        return 'A tool with an inaccessible name method.';
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
