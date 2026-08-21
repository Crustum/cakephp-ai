<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Ai;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Providers\Tools\WebFetch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\NamedTool;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.xai', [

        ...(array)Configure::read('Ai.providers.xai'),
        'key' => 'test-key',
    ]);
});

test('tool with parameters includes correct schema', function (): void {
    aiHttpFake([
        '*' => fakeXaiToolMappingResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a random number', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'function')->first();

        return $tool['parameters']['type'] === 'object'
            && array_key_exists('min', $tool['parameters']['properties'])
            && array_key_exists('max', $tool['parameters']['properties'])
            && in_array('min', $tool['parameters']['required'])
            && in_array('max', $tool['parameters']['required'])
            && $tool['parameters']['additionalProperties'] === false;
    });
});

test('tool with empty schema includes parameters', function (): void {
    aiHttpFake([
        '*' => fakeXaiToolMappingResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator()])->prompt('Give me a random number', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'function')->first();

        return array_key_exists('parameters', $tool)
            && $tool['parameters']['type'] === 'object'
            && $tool['parameters']['properties'] === []
            && $tool['parameters']['required'] === []
            && $tool['parameters']['additionalProperties'] === false;
    });
});

test('tool with a name() method emits the declared name', function (): void {
    aiHttpFake(['*' => fakeXaiToolMappingResponse('ok')]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $names = collect(Hash::get($body, 'tools'))->extract('name')->toList();

        return in_array('my_custom_tool', $names, true);
    });
});

test('tool parameters are not wrapped in schema definition', function (): void {
    aiHttpFake([
        '*' => fakeXaiToolMappingResponse('done'),
    ]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a random number', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'function')->first();

        return ! array_key_exists('schema_definition', $tool['parameters']['properties'] ?? [])
            && ! in_array('schema_definition', $tool['parameters']['required'] ?? []);
    });
});

test('web search tool sends type web_search', function (): void {
    aiHttpFake(['*' => fakeXaiToolMappingResponse('result')]);

    agent(tools: [new WebSearch()])->prompt('Search the web', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'web_search')->first();

        return $tool !== null;
    });
});

test('web search tool sends allowed_domains', function (): void {
    aiHttpFake(['*' => fakeXaiToolMappingResponse('result')]);

    agent(tools: [(new WebSearch())->allow(['example.com', 'docs.example.com'])])
        ->prompt('Search', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'web_search')->first();

        return Hash::get($tool, 'allowed_domains') === ['example.com', 'docs.example.com'];
    });
});

test('web search tool forwards xai provider options into the tool payload', function (): void {
    aiHttpFake(['*' => fakeXaiToolMappingResponse('result')]);

    agent(tools: [
        (new WebSearch())->withProviderOptions([
            'excluded_domains' => ['spam.example.com'],
            'enable_image_understanding' => true,
            'enable_image_search' => true,
        ]),
    ])->prompt('Search', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'web_search')->first();

        return Hash::get($tool, 'excluded_domains') === ['spam.example.com']
            && Hash::get($tool, 'enable_image_understanding') === true
            && Hash::get($tool, 'enable_image_search') === true;
    });
});

test('file search tool sends file_search with vector store ids', function (): void {
    aiHttpFake(['*' => fakeXaiToolMappingResponse('result')]);

    agent(tools: [new FileSearch(['collection-id'])])->prompt('Search my docs', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'file_search')->first();

        return Hash::get($tool, 'vector_store_ids') === ['collection-id'];
    });
});

test('file search metadata filters throw an exception', function (): void {
    $search = new FileSearch(['collection-id'], where: ['company' => 'cakephp']);

    expect(fn() => Ai::manager()->provider('xai')->fileSearchToolOptions($search))
        ->toThrow(InvalidArgumentException::class, 'xAI does not support file search metadata filters.');
});

test('file search tool forwards xai provider options into the tool payload', function (): void {
    aiHttpFake(['*' => fakeXaiToolMappingResponse('result')]);

    agent(tools: [
        (new FileSearch(['collection-id']))->withProviderOptions([
            'max_num_results' => 5,
        ]),
    ])->prompt('Search my docs', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'file_search')->first();

        return Hash::get($tool, 'max_num_results') === 5;
    });
});

test('unsupported provider tools are omitted from the tools payload', function (): void {
    aiHttpFake(['*' => fakeXaiToolMappingResponse('result')]);

    agent(tools: [new WebFetch(), new WebSearch()])
        ->prompt('Search', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'tools') === [['type' => 'web_search']];
    });
});

function fakeXaiToolMappingResponse(string $text): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ]);
}
