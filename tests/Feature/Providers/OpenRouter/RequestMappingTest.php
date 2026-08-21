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

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

test('request includes model and messages', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'openrouter', model: 'anthropic/claude-sonnet-4.6');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'anthropic/claude-sonnet-4.6'
            && count($body['messages']) >= 1
            && collect($body['messages'])->some(fn($m): bool => $m['role'] === 'user' && $m['content'] === 'Hi there');
    });
});

test('system instructions are sent as system message', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    (new AssistantAgent())->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'system')->first();

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    (new AttributeAgent())->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'temperature') === 0.7
            && Hash::get($body, 'max_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_tokens', $body);
    });
});

test('tools include tool choice auto', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('42')]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a number', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && $body['tools'] !== [];
    });
});

test('request without tools excludes tool fields', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('required tool choice forces the model to call a tool', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('42')]);

    (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('required tool choice can be set via attribute', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('42')]);

    (new AttributeToolChoiceAgent())->prompt('Give me a number', provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('named tool choice forces a specific function', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('42')]);

    (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === [
        'type' => 'function',
        'function' => ['name' => 'custom_named_tool'],
    ]);
});

test('none tool choice prevents tool calls', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Sure')]);

    (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
});

test('structured output includes json schema response format', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('{"symbol": "Au"}')]);

    (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $format = Hash::get($body, 'response_format');

        return $format['type'] === 'json_schema'
            && isset($format['json_schema']['name'])
            && isset($format['json_schema']['schema'])
            && $format['json_schema']['strict'] === true;
    });
});

test('request without schema excludes response format', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('response_format', $body);
    });
});

test('streaming request includes stream options', function (): void {
    aiHttpFake(['*' => aiHttpResponse("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

    $stream = agent()->stream('Hello', provider: 'openrouter');

    foreach ($stream as $event) {
    }

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true
            && Hash::get($body, 'stream_options.include_usage') === true;
    });
});

test('request sends bearer token authorization', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('request sends http referer and x openrouter title headers when configured', function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
    Configure::write('Ai.providers.openrouter.http_referer', 'https://example.com');
    Configure::write('Ai.providers.openrouter.x_title', 'My App');

    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('HTTP-Referer', 'https://example.com')
        && $request->hasHeader('X-OpenRouter-Title', 'My App'));
});

test('response text is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('CakePHP is great')]);

    $response = agent()->prompt('Tell me about CakePHP', provider: 'openrouter');

    expect($response->text)->toBe('CakePHP is great')
        ->and($response->meta->provider)->toBe('openrouter');
});

test('response usage is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'openrouter');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('response usage includes cache and reasoning tokens', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'prompt_tokens_details' => [
                'cached_tokens' => 20,
                'cache_write_tokens' => 80,
            ],
            'completion_tokens_details' => [
                'reasoning_tokens' => 10,
            ],
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'openrouter');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50)
        ->and($response->usage->cacheReadInputTokens)->toBe(20)
        ->and($response->usage->cacheWriteInputTokens)->toBe(80)
        ->and($response->usage->reasoningTokens)->toBe(10);
});

test('structured response is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'openrouter');

    expect($response->structured['symbol'])->toBe('Au');
});

test('web search citations are extracted from message annotations', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => 'Paris is the capital of France.',
                'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url_citation' => [
                            'url' => 'https://example.com/paris',
                            'title' => 'Paris - Wikipedia',
                            'start_index' => 0,
                            'end_index' => 30,
                        ],
                    ],
                    [
                        'type' => 'url_citation',
                        'url_citation' => [
                            'url' => 'https://example.com/france',
                            'title' => 'France - Wikipedia',
                            'start_index' => 31,
                            'end_index' => 50,
                        ],
                    ],
                ],
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    $response = agent()->prompt('What is the capital of France?', provider: 'openrouter');

    expect($response->meta->citations)->toHaveCount(2)
        ->and($response->meta->citations[0]->url)->toBe('https://example.com/paris')
        ->and($response->meta->citations[0]->title)->toBe('Paris - Wikipedia')
        ->and($response->meta->citations[0]->startIndex)->toBe(0)
        ->and($response->meta->citations[0]->endIndex)->toBe(30)
        ->and($response->meta->citations[1]->url)->toBe('https://example.com/france')
        ->and($response->meta->citations[1]->title)->toBe('France - Wikipedia')
        ->and($response->meta->citations[1]->startIndex)->toBe(31)
        ->and($response->meta->citations[1]->endIndex)->toBe(50);
});

test('web search citations omit span indices when not provided', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => 'Some answer.',
                'annotations' => [[
                    'type' => 'url_citation',
                    'url_citation' => [
                        'url' => 'https://example.com/source',
                        'title' => 'Source',
                    ],
                ]],
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
    ])]);

    $response = agent()->prompt('Question', provider: 'openrouter');

    expect($response->meta->citations)->toHaveCount(1)
        ->and($response->meta->citations[0]->url)->toBe('https://example.com/source')
        ->and($response->meta->citations[0]->startIndex)->toBeNull()
        ->and($response->meta->citations[0]->endIndex)->toBeNull();
});

test('response with no annotations has empty citations collection', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    $response = agent()->prompt('Hi', provider: 'openrouter');

    expect($response->meta->citations)->toHaveCount(0);
});
