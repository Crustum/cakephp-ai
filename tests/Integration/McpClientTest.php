<?php
declare(strict_types=1);

use Crustum\Ai\Test\Support\Skips\ApiKey;
use Crustum\Ai\Tools\McpTool;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use Crustum\Mcp\Client;

function requiresMcpServerEverything(): void
{
    if (! is_executable(trim((string)shell_exec('command -v npx 2>/dev/null')))) {
        test()->markTestSkipped('Missing npx — skipping live MCP server test.');
    }
}

test('every weird MCP tool from the everything server works across providers', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);
    requiresMcpServerEverything();

    $client = Client::local('npx', ['-y', '@modelcontextprotocol/server-everything'])
        ->withTimeout(60)
        ->connect();

    try {
        $tools = $client->tools();

        expect($tools)->not->toBeEmpty();

        foreach ($tools as $tool) {
            expect((new McpTool($tool))->schema(new JsonSchemaTypeFactory()))->toBeArray();
        }

        $response = agent(
            instructions: 'You echo text for the user. Always call the echo tool with the exact text provided, then reply with the tool output verbatim.',
            tools: $tools->toList(),
        )->prompt(
            "Echo the text 'hello-mcp-123' using the echo tool.",
            provider: $provider,
            model: $model,
        );

        expect($response->toolCalls->contains(fn($call): bool => $call->name === 'mcp_tools_echo'))->toBeTrue()
            ->and($response->text)->toContain('hello-mcp-123');
    } finally {
        $client->disconnect();
    }
})->with('agent-providers');
