<?php
declare(strict_types=1);

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Test\Fixtures\Mcp\FakeMcpClient;
use Crustum\Ai\Test\Fixtures\Mcp\FakeMcpTool;
use Crustum\Ai\Test\Fixtures\Mcp\FakeMcpToolResult;
use Crustum\Ai\Tools\McpTool;
use Crustum\Ai\Trait\PromptableTrait;

test('agents can return mcp client tools directly', function (): void {
    $client = new FakeMcpClient();
    $mcpTool = new FakeMcpTool($client, 'search', null, 'Search records.', [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
            ],
        ],
        'required' => ['query'],
    ]);

    $client->results['search'] = new FakeMcpToolResult([
        ['type' => 'text', 'text' => 'Found results.'],
    ], false);

    $agent = new class ($mcpTool) implements Agent, HasTools
    {
        use PromptableTrait;

        public function __construct(public object $tool)
        {
        }

        public function instructions(): string
        {
            return 'Use available tools.';
        }

        public function tools(): iterable
        {
            return [$this->tool];
        }
    };

    $agent::fake([
        new ToolCall('call_123', 'mcp_tools_search', ['query' => 'cakephp']),
        'Done.',
    ]);

    $response = $agent->prompt('Search for CakePHP');

    expect($response)
        ->toolCalls->toHaveCount(1)
        ->toolResults->toHaveCount(1);

    expect($response->toolResults->first())->toHaveProperty('result', 'Found results.');

    expect($client)->toHaveProperty(
        'toolCalls',
        [
            ['name' => 'search', 'arguments' => ['query' => 'cakephp']],
        ],
    );
});

test('it runs mcp client tools whose schema uses unrepresentable json schema', function (): void {
    $client = new FakeMcpClient();

    $union = new McpTool(new FakeMcpTool($client, 'set_value', null, 'Set a value.', [
        'type' => 'object',
        'properties' => [
            'value' => ['type' => ['string', 'number', 'boolean']],
            'mode' => ['oneOf' => [['const' => 'fast'], ['const' => 'slow']]],
        ],
        'required' => ['value'],
    ]));

    $client->results['set_value'] = new FakeMcpToolResult([
        ['type' => 'text', 'text' => 'Value set.'],
    ], false);

    $agent = new class ($union) implements Agent, HasTools
    {
        use PromptableTrait;

        public function __construct(public object $tool)
        {
        }

        public function instructions(): string
        {
            return 'Use available tools.';
        }

        public function tools(): iterable
        {
            return [$this->tool];
        }
    };

    $agent::fake([
        new ToolCall('call_union', 'mcp_tools_set_value', ['value' => 'bug']),
        'Done.',
    ]);

    $response = $agent->prompt('Set the value');

    expect($response->toolResults)
        ->toHaveCount(1)
        ->first()->toHaveProperty('result', 'Value set.');

    expect($client)->toHaveProperty(
        'toolCalls',
        [
            ['name' => 'set_value', 'arguments' => ['value' => 'bug']],
        ],
    );
});
