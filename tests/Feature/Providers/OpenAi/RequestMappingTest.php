<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\AttributeAgent;
use Crustum\Ai\Test\Fixtures\Agents\AttributeToolChoiceAgent;
use Crustum\Ai\Test\Fixtures\Agents\NestedStructuredAgent;
use Crustum\Ai\Test\Fixtures\Agents\StructuredAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolChoiceAgent;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\TestSuite\Http\RecordedHttp;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('request includes model and input', function (): void {
    $this->fakeProviderHttp(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'openai', model: 'gpt-5.4');

    $this->assertHttpSent(
        fn(RecordedHttp $request): bool => $request->json('model') === 'gpt-5.4'
            && $request->hasUserText('Hi there'),
    );
});

test('system instructions are sent as system message in input', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('Hello')]);

    (new AssistantAgent())->prompt('Hello', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'system')->first();

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});

test('temperature, max tokens, and top_p are included when set via attributes', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('Hello')]);

    (new AttributeAgent())->prompt('Hello', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'temperature') === 0.7
            && Hash::get($body, 'max_output_tokens') === 4096
            && Hash::get($body, 'top_p') === 0.8;
    });
});

test('temperature, max tokens, and top_p are excluded when not set', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_output_tokens', $body)
            && ! array_key_exists('top_p', $body);
    });
});

test('tools include tool choice auto', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('42')]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a number', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && $body['tools'] !== [];
    });
});

test('request without tools excludes tool fields', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('structured output includes json schema text format', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('{"symbol": "Au"}')]);

    (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $format = Hash::get($body, 'text.format');

        return $format['type'] === 'json_schema'
            && isset($format['name'])
            && isset($format['schema'])
            && $format['strict'] === true;
    });
});

test('structured agent without Strict attribute sends strict false in text format', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('{"elements": []}')]);

    (new NestedStructuredAgent())->prompt('List elements.', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $format = Hash::get($body, 'text.format');

        return $format['type'] === 'json_schema'
            && $format['strict'] === false;
    });
});

test('request without schema excludes text format', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('text', $body);
    });
});

test('request sends bearer token authorization', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('response text is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('CakePHP is great')]);

    $response = agent()->prompt('Tell me about CakePHP', provider: 'openai');

    expect($response->text)->toBe('CakePHP is great')
        ->and($response->meta->provider)->toBe('openai');
});

test('response usage is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => 'Hello',
            ]],
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'openai');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('structured response is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'openai');

    expect($response->structured['symbol'])->toBe('Au');
});

test('citations preserve every annotation with span indices', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
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

    $response = agent()->prompt('Give me sources', provider: 'openai');

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
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => 'Sources',
                'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/one',
                        'title' => 'One',
                    ],
                ],
            ]],
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Give me sources', provider: 'openai');

    expect($response->meta->citations)->toHaveCount(1)
        ->and($response->meta->citations[0]->startIndex)->toBeNull()
        ->and($response->meta->citations[0]->endIndex)->toBeNull();
});

test('required tool choice forces the model to call a tool', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('42')]);

    (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('required tool choice can be set via attribute', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('42')]);

    (new AttributeToolChoiceAgent())->prompt('Give me a number', provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('named tool choice forces a specific function', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('42')]);

    (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === [
        'type' => 'function',
        'name' => 'custom_named_tool',
    ]);
});

test('none tool choice prevents tool calls', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('Sure')]);

    (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
});

test('response usage captures cache write tokens', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.6-luna',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => 'Hello']],
        ]],
        'usage' => [
            'input_tokens' => 8817,
            'output_tokens' => 120,
            'input_tokens_details' => [
                'cache_write_tokens' => 8814,
                'cached_tokens' => 0,
            ],
            'output_tokens_details' => [
                'reasoning_tokens' => 64,
            ],
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'openai');

    expect($response->usage->cacheWriteInputTokens)->toBe(8814)
        ->and($response->usage->cacheReadInputTokens)->toBe(0)
        ->and($response->usage->promptTokens)->toBe(3)
        ->and($response->usage->completionTokens)->toBe(120)
        ->and($response->usage->reasoningTokens)->toBe(64);
});

test('response usage separates cache reads from cache writes', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.6-luna',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => 'Hello']],
        ]],
        'usage' => [
            'input_tokens' => 8817,
            'output_tokens' => 120,
            'input_tokens_details' => [
                'cache_write_tokens' => 0,
                'cached_tokens' => 8814,
            ],
            'output_tokens_details' => [
                'reasoning_tokens' => 64,
            ],
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'openai');

    expect($response->usage->cacheWriteInputTokens)->toBe(0)
        ->and($response->usage->cacheReadInputTokens)->toBe(8814)
        ->and($response->usage->promptTokens)->toBe(3);
});
