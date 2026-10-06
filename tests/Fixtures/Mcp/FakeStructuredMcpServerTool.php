<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Mcp;

use Crustum\JsonSchema\Contracts\JsonSchema;
use Crustum\Mcp\Request;
use Crustum\Mcp\Response;
use Crustum\Mcp\ResponseFactory;
use Crustum\Mcp\Server\Tool;
use Override;

class FakeStructuredMcpServerTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured([
            'temperature' => 72,
            'conditions' => 'Sunny',
            'url' => 'https://example.com/report',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->required(),
        ];
    }
}
