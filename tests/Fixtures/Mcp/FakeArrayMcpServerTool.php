<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Mcp;

use Crustum\JsonSchema\Contracts\JsonSchema;
use Crustum\Mcp\Request;
use Crustum\Mcp\Response;
use Crustum\Mcp\Server\Tool;
use Override;

class FakeArrayMcpServerTool extends Tool
{
    /**
     * @return array<int, Response|string>
     */
    public function handle(Request $request): array
    {
        return [
            Response::text('First. '),
            'Second. ',
            Response::text('Third.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
