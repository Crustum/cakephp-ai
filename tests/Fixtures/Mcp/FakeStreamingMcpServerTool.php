<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Mcp;

use Crustum\JsonSchema\Contracts\JsonSchema;
use Crustum\Mcp\Request;
use Crustum\Mcp\Response;
use Crustum\Mcp\Server\Tool;
use Generator;
use Override;

class FakeStreamingMcpServerTool extends Tool
{
    /**
     * @return Generator<int, Response>
     */
    public function handle(Request $request): Generator
    {
        yield Response::notification('processing/progress', ['step' => 1]);
        yield Response::text('First. ');
        yield 'Second. ';
        yield Response::make([Response::text('Third.')]);
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
