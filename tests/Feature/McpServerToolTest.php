<?php
declare(strict_types=1);

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Test\Fixtures\Mcp\FakeMcpServerTool;
use Crustum\Ai\Tools\McpServerTool;
use Crustum\Ai\Trait\PromptableTrait;
use Crustum\Mcp\Server\Tools\Annotations\IsReadOnly;

test('agents can return mcp server tools directly', function (): void {
    $serverTool = new FakeMcpServerTool();

    $agent = new class ($serverTool) implements Agent, HasTools
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
        new ToolCall('call_123', 'fake-mcp-server-tool', ['city' => 'Paris']),
        'Done.',
    ]);

    $response = $agent->prompt('What is the weather in Paris?');

    expect($response)
        ->toolCalls->toHaveCount(1)
        ->toolResults->toHaveCount(1);

    expect($response->toolResults->first())->toHaveProperty('result', 'Sunny in Paris.');

    expect($serverTool->invocations)->toBe([['city' => 'Paris']]);
});

test('mcp server tools expose their annotations', function (): void {
    $tool = new #[IsReadOnly] class extends FakeMcpServerTool {
    };

    expect((new McpServerTool($tool))->annotations())->toBe(['readOnlyHint' => true]);
});
