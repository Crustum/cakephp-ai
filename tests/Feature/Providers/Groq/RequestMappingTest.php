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
    Configure::write('Ai.providers.groq', [

        ...(array)Configure::read('Ai.providers.groq'),
        'key' => 'test-key',
    ]);
});

test('request includes model and messages', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'groq', model: 'llama-3.3-70b-versatile');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'llama-3.3-70b-versatile'
            && count($body['messages']) >= 1
            && collect($body['messages'])->some(fn($m): bool => $m['role'] === 'user' && $m['content'] === 'Hi there');
    });
});

test('system instructions are sent as system message', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('Hello')]);

    (new AssistantAgent())->prompt('Hello', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('Hello')]);

    (new AttributeAgent())->prompt('Hello', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'temperature') === 0.7
            && Hash::get($body, 'max_completion_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('Hello')]);

    agent()->prompt('Hello', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_completion_tokens', $body);
    });
});

test('tools include tool choice auto', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('42')]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a number', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && $body['tools'] !== [];
    });
});

test('request without tools excludes tool fields', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('Hello')]);

    agent()->prompt('Hello', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('required tool choice forces the model to call a tool', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('42')]);

    (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('required tool choice can be set via attribute', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('42')]);

    (new AttributeToolChoiceAgent())->prompt('Give me a number', provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('named tool choice forces a specific function', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('42')]);

    (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === [
        'type' => 'function',
        'function' => ['name' => 'custom_named_tool'],
    ]);
});

test('none tool choice prevents tool calls', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('Sure')]);

    (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
});

test('structured output includes json schema response format', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('{"symbol": "Au"}')]);

    (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'groq');

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
    aiHttpFake(['*' => fakeGroqResponse('Hello')]);

    agent()->prompt('Hello', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('response_format', $body);
    });
});

test('schema combined with tools omits response format but keeps schema instructions', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('{"number": 42}')]);

    agent(
        tools: [new RandomNumberGenerator()],
        schema: fn($s): array => ['number' => $s->integer()->required()],
    )->prompt('Give me a number', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return ! array_key_exists('response_format', $body)
            && is_array($body['tools'])
            && $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'JSON object that strictly adheres');
    });
});

test('streaming request includes stream options', function (): void {
    aiHttpFake(['*' => aiHttpResponse("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

    $stream = agent()->stream('Hello', provider: 'groq');

    // Consume the stream
    foreach ($stream as $event) {
    }

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true
            && Hash::get($body, 'stream_options.include_usage') === true;
    });
});

test('request sends bearer token authorization', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('Hello')]);

    agent()->prompt('Hello', provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('response text is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('CakePHP is great')]);

    $response = agent()->prompt('Tell me about CakePHP', provider: 'groq');

    expect($response->text)->toBe('CakePHP is great')
        ->and($response->meta->provider)->toBe('groq');
});

test('response usage is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
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

    $response = agent()->prompt('Hello', provider: 'groq');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('response usage includes reasoning tokens', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-r1-1',
        'object' => 'chat.completion',
        'model' => 'deepseek-r1-distill-llama-70b',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'The answer is 4.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'completion_tokens_details' => [
                'reasoning_tokens' => 20,
            ],
        ],
    ])]);

    $response = agent()->prompt('What is 2+2?', provider: 'groq', model: 'deepseek-r1-distill-llama-70b');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50)
        ->and($response->usage->reasoningTokens)->toBe(20);
});

test('response usage includes cached prompt tokens', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-cache-1',
        'object' => 'chat.completion',
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 4641,
            'completion_tokens' => 1817,
            'total_tokens' => 6458,
            'prompt_tokens_details' => [
                'cached_tokens' => 4608,
            ],
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'groq');

    expect($response->usage->promptTokens)->toBe(4641)
        ->and($response->usage->completionTokens)->toBe(1817)
        ->and($response->usage->cacheReadInputTokens)->toBe(4608);
});

test('structured response is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeGroqResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'groq');

    expect($response->structured['symbol'])->toBe('Au');
});
