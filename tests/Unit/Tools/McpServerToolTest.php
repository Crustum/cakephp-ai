<?php
declare(strict_types=1);

use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Test\Fixtures\Mcp\FakeArrayMcpServerTool;
use Crustum\Ai\Test\Fixtures\Mcp\FakeErroringMcpServerTool;
use Crustum\Ai\Test\Fixtures\Mcp\FakeMcpServerTool;
use Crustum\Ai\Test\Fixtures\Mcp\FakeStreamingMcpServerTool;
use Crustum\Ai\Test\Fixtures\Mcp\FakeStructuredMcpServerTool;
use Crustum\Ai\Tools\McpServerTool;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use Crustum\Mcp\Request as McpRequest;
use Crustum\Mcp\Response;
use Crustum\Mcp\Server\Tool;
use Crustum\Mcp\Support\ContainerRegistry;

test('it detects mcp server tool primitives', function (): void {
    expect(McpServerTool::supports(new FakeMcpServerTool()))->toBeTrue()
        ->and(McpServerTool::supports(new stdClass()))->toBeFalse();
});

test('it exposes the tool name, description, and schema', function (): void {
    $tool = new McpServerTool(new FakeMcpServerTool());

    expect($tool->name())->toBe('fake-mcp-server-tool')
        ->and($tool->description())->toBe('Fetches the current weather for a city.');

    $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory())))->toSchema();

    expect($schema)->toMatchArray([
        'required' => ['city'],
        'properties' => [
            'city' => [
                'type' => 'string',
                'description' => 'The city to get the weather for.',
            ],
            'units' => [
                'type' => 'string',
                'enum' => ['celsius', 'fahrenheit'],
                'default' => 'celsius',
            ],
        ],
    ]);
});

test('it invokes the underlying tool and returns text content', function (): void {
    $serverTool = new FakeMcpServerTool();
    $tool = new McpServerTool($serverTool);

    $result = $tool->handle(new Request(['city' => 'Paris']));

    expect($result)->toBe('Sunny in Paris.')
        ->and($serverTool->invocations)->toBe([['city' => 'Paris']]);
});

test('it serializes structured tool responses as json', function (): void {
    $tool = new McpServerTool(new FakeStructuredMcpServerTool());

    $result = $tool->handle(new Request(['city' => 'Paris']));

    expect($result)->toBeJson()
        ->and(json_decode($result, true))->toMatchArray([
            'temperature' => 72,
            'conditions' => 'Sunny',
        ]);
});

test('it does not escape slashes in structured content json', function (): void {
    $tool = new McpServerTool(new FakeStructuredMcpServerTool());

    expect($tool->handle(new Request(['city' => 'Paris'])))
        ->toContain('"url":"https://example.com/report"');
});

test('it surfaces tool errors with the standard prefix', function (): void {
    $tool = new McpServerTool(new FakeErroringMcpServerTool());

    expect($tool->handle(new Request()))->toBe('MCP tool error: Something went wrong.');
});

test('it returns only the final yielded response and ignores notifications and intermediate updates', function (): void {
    $tool = new McpServerTool(new FakeStreamingMcpServerTool());

    expect($tool->handle(new Request()))->toBe('Third.');
});

test('it returns only the final item from an array response', function (): void {
    $tool = new McpServerTool(new FakeArrayMcpServerTool());

    expect($tool->handle(new Request()))->toBe('Third.');
});

test('lazily yielded responses still resolve the scoped mcp request', function (): void {
    $serverTool = new class extends Tool
    {
        public function handle(McpRequest $request): Generator
        {
            yield Response::text('First. ');
            yield Response::text(ContainerRegistry::getInstance()->get(McpRequest::class)->get('city'));
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $tool = new McpServerTool($serverTool);

    expect($tool->handle(new Request(['city' => 'Paris'])))->toBe('Paris');
});

test('it includes app resource uri when tool returns text and ui resource link', function (): void {
    $serverTool = new class extends Tool
    {
        public function handle(McpRequest $request): mixed
        {
            return Response::make([
                Response::text('dashboard loaded.'),
                Response::resourceLink('ui://resources/weather-dashboard-app', 'weather-app'),
            ]);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $result = (new McpServerTool($serverTool))->handle(new Request());

    expect($result)->toBeJson()
        ->and(json_decode($result, true))->toBe([
            'text' => 'dashboard loaded.',
            'appResourceUri' => 'ui://resources/weather-dashboard-app',
        ])
        ->and($result)->toContain('ui://resources/weather-dashboard-app');
});

test('it resolves app resource uri regardless of content order', function (): void {
    $serverTool = new class extends Tool
    {
        /**
         * @return array<int, Response>
         */
        public function handle(McpRequest $request): array
        {
            return [
                Response::resourceLink('ui://resources/weather-dashboard-app', 'weather-app'),
                Response::text('dashboard loaded.'),
            ];
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $result = (new McpServerTool($serverTool))->handle(new Request());

    expect(json_decode($result, true))->toBe([
        'text' => 'dashboard loaded.',
        'appResourceUri' => 'ui://resources/weather-dashboard-app',
    ]);
});

test('it ignores notifications when resolving app resource uri', function (): void {
    $serverTool = new class extends Tool
    {
        public function handle(McpRequest $request): Generator
        {
            yield Response::notification('processing/progress', ['step' => 1]);
            yield Response::text('dashboard loaded.');
            yield Response::resourceLink('ui://resources/weather-dashboard-app', 'weather-app');
            yield Response::notification('processing/progress', ['step' => 2]);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $result = (new McpServerTool($serverTool))->handle(new Request());

    expect(json_decode($result, true))->toBe([
        'text' => 'dashboard loaded.',
        'appResourceUri' => 'ui://resources/weather-dashboard-app',
    ]);
});

test('it ignores non-ui resource links for app rendering', function (): void {
    $serverTool = new class extends Tool
    {
        /**
         * @return array<int, Response>
         */
        public function handle(McpRequest $request): array
        {
            return [
                Response::text('dashboard loaded.'),
                Response::resourceLink('https://example.com/other', 'other'),
            ];
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $result = (new McpServerTool($serverTool))->handle(new Request());

    expect($result)->toBe('https://example.com/other');
});

test('it preserves error prefix when app resource is present', function (): void {
    $serverTool = new class extends Tool
    {
        /**
         * @return array<int, Response>
         */
        public function handle(McpRequest $request): array
        {
            return [
                Response::error('Something went wrong.'),
                Response::resourceLink('ui://resources/weather-dashboard-app', 'weather-app'),
            ];
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $result = (new McpServerTool($serverTool))->handle(new Request());

    expect(json_decode($result, true))->toBe([
        'text' => 'MCP tool error: Something went wrong.',
        'appResourceUri' => 'ui://resources/weather-dashboard-app',
    ]);
});
