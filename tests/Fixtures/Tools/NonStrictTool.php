<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Tools;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;

class NonStrictTool implements Tool
{
    public function description(): string
    {
        return 'A tool without the Strict attribute.';
    }

    public function handle(Request $request): string
    {
        return 'ok';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            'limit' => $schema->integer(),
        ];
    }
}
