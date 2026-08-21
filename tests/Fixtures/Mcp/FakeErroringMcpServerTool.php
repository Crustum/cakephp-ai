<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Mcp;

use Crustum\JsonSchema\Contracts\JsonSchema;
use Crustum\Mcp\Request;
use Crustum\Mcp\Response;
use Crustum\Mcp\Server\Tool;
use Override;

class FakeErroringMcpServerTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::error('Something went wrong.');
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
