<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\AttributeAgent;
use Crustum\Ai\Test\Fixtures\Agents\AttributeToolChoiceAgent;
use Crustum\Ai\Test\Fixtures\Agents\StructuredAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolChoiceAgent;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.xai', [

        ...(array)Configure::read('Ai.providers.xai'),
        'key' => 'test-key',
    ]);
});

test('request includes model and input', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'xai', model: 'grok-4-1-fast-reasoning');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'grok-4-1-fast-reasoning'
            && is_array($body['input'])
            && collect($body['input'])->some(fn($m): bool => $m['role'] === 'user'
                && collect($m['content'])->some(fn($c): bool => ($c['type'] ?? '') === 'input_text' && $c['text'] === 'Hi there'));
    });
});

test('system instructions are sent as system message', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    (new AssistantAgent())->prompt('Hello', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    (new AttributeAgent())->prompt('Hello', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'temperature') === 0.7
            && Hash::get($body, 'max_output_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    agent()->prompt('Hello', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_output_tokens', $body);
    });
});

test('tools include tool choice auto', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('42')]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a number', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && $body['tools'] !== [];
    });
});

test('request without tools excludes tool fields', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    agent()->prompt('Hello', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('required tool choice forces the model to call a tool', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('42')]);

    (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'xai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('required tool choice can be set via attribute', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('42')]);

    (new AttributeToolChoiceAgent())->prompt('Give me a number', provider: 'xai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('named tool choice forces a specific function', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('42')]);

    (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'xai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === [
        'type' => 'function',
        'name' => 'custom_named_tool',
    ]);
});

test('none tool choice prevents tool calls', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('Sure')]);

    (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'xai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
});

test('structured output includes json schema text format', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('{"symbol": "Au"}')]);

    (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $format = Hash::get($body, 'text.format');

        return $format['type'] === 'json_schema'
            && isset($format['name'])
            && isset($format['schema'])
            && $format['strict'] === true;
    });
});

test('request without schema excludes text format', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    agent()->prompt('Hello', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('text', $body);
    });
});

test('streaming request includes stream flag', function (): void {
    $sseData = "data: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_123\",\"model\":\"grok-4-1-fast-reasoning\"}}\n\n"
        . "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hi\"}\n\n"
        . "data: {\"type\":\"response.output_text.done\"}\n\n"
        . "data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_123\",\"usage\":{\"input_tokens\":1,\"output_tokens\":1}}}\n\n";

    aiHttpFake(['*' => aiHttpResponse($sseData)]);

    $stream = agent()->stream('Hello', provider: 'xai');

    foreach ($stream as $event) {
    }

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true;
    });
});

test('request sends bearer token authorization', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    agent()->prompt('Hello', provider: 'xai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('response text is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('CakePHP is great')]);

    $response = agent()->prompt('Tell me about CakePHP', provider: 'xai');

    expect($response->text)->toBe('CakePHP is great')
        ->and($response->meta->provider)->toBe('xai');
});

test('response usage is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
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
                    ['type' => 'output_text', 'text' => 'Hello'],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
            'input_tokens_details' => ['cached_tokens' => 2],
            'output_tokens_details' => ['reasoning_tokens' => 3],
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'xai');

    expect($response->usage->inputTokens)->toBe(10)
        ->and($response->usage->outputTokens)->toBe(8)
        ->and($response->usage->cacheReadInputTokens)->toBe(2)
        ->and($response->usage->reasoningTokens)->toBe(3);
});

test('structured response is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeXaiRequestMappingResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'xai');

    expect($response->structured['symbol'])->toBe('Au');
});

test('citations preserve every annotation with span indices', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [[
                'type' => 'output_text',
                'text' => 'Here are sources',
                'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/one',
                        'title' => 'Same Title',
                        'start_index' => 0,
                        'end_index' => 10,
                    ],
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/two',
                        'title' => 'Same Title',
                        'start_index' => 11,
                        'end_index' => 25,
                    ],
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/one',
                        'title' => 'Same Title',
                        'start_index' => 26,
                        'end_index' => 40,
                    ],
                ],
            ]],
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Give me sources', provider: 'xai');

    expect($response->meta->citations)->toHaveCount(3)
        ->and($response->meta->citations[0]->url)->toBe('https://example.com/one')
        ->and($response->meta->citations[0]->startIndex)->toBe(0)
        ->and($response->meta->citations[0]->endIndex)->toBe(10)
        ->and($response->meta->citations[1]->url)->toBe('https://example.com/two')
        ->and($response->meta->citations[1]->startIndex)->toBe(11)
        ->and($response->meta->citations[2]->url)->toBe('https://example.com/one')
        ->and($response->meta->citations[2]->startIndex)->toBe(26);
});

test('citations omit span indices when not provided by the api', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [[
                'type' => 'output_text',
                'text' => 'Sources',
                'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/a',
                        'title' => 'A',
                    ],
                ],
            ]],
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Give me sources', provider: 'xai');

    expect($response->meta->citations)->toHaveCount(1)
        ->and($response->meta->citations[0]->url)->toBe('https://example.com/a')
        ->and($response->meta->citations[0]->startIndex)->toBeNull()
        ->and($response->meta->citations[0]->endIndex)->toBeNull();
});

function fakeXaiRequestMappingResponse(string $text): AiHttpResponseDefinition
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
