<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Tools;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;

class DeferredTool implements Tool
{
    public function description(): string
    {
        return 'A deferred tool whose definition is loaded on demand.';
    }

    public function handle(Request $request): string
    {
        return 'done';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
