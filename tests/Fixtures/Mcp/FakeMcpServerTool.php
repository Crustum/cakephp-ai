<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Mcp;

use Crustum\JsonSchema\Contracts\JsonSchema;
use Crustum\Mcp\Request;
use Crustum\Mcp\Response;
use Crustum\Mcp\Server\Attributes\Description;
use Crustum\Mcp\Server\Tool;
use Override;

#[Description('Fetches the current weather for a city.')]
class FakeMcpServerTool extends Tool
{
    public array $invocations = [];

    public function handle(Request $request): Response
    {
        $this->invocations[] = $request->all();

        return Response::text('Sunny in ' . $request->get('city') . '.');
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()
                ->description('The city to get the weather for.')
                ->required(),
            'units' => $schema->string()
                ->enum(['celsius', 'fahrenheit'])
                ->default('celsius'),
        ];
    }
}
