<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\AttributeAgent;
use Crustum\Ai\Test\Fixtures\Agents\AttributeToolChoiceAgent;
use Crustum\Ai\Test\Fixtures\Agents\StructuredAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolChoiceAgent;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.deepseek', [

        ...(array)Configure::read('Ai.providers.deepseek'),
        'key' => 'test-key',
    ]);
});

test('request includes model and messages', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'deepseek', model: 'deepseek-chat');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'deepseek-chat'
            && count($body['messages']) >= 1
            && collect($body['messages'])->some(fn($m): bool => $m['role'] === 'user' && $m['content'] === 'Hi there');
    });
});

test('system instructions are sent as system message', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('Hello')]);

    (new AssistantAgent())->prompt('Hello', provider: 'deepseek');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('Hello')]);

    (new AttributeAgent())->prompt('Hello', provider: 'deepseek');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'temperature') === 0.7
            && Hash::get($body, 'max_completion_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('Hello')]);

    agent()->prompt('Hello', provider: 'deepseek');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_completion_tokens', $body);
    });
});

test('tools include tool choice auto', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('42')]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a number', provider: 'deepseek');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && $body['tools'] !== [];
    });
});

test('request without tools excludes tool fields', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('Hello')]);

    agent()->prompt('Hello', provider: 'deepseek');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('required tool choice forces the model to call a tool', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('42')]);

    (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'deepseek');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('required tool choice can be set via attribute', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('42')]);

    (new AttributeToolChoiceAgent())->prompt('Give me a number', provider: 'deepseek');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('named tool choice forces a specific function', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('42')]);

    (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'deepseek');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === [
        'type' => 'function',
        'function' => ['name' => 'custom_named_tool'],
    ]);
});

test('none tool choice prevents tool calls', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('Sure')]);

    (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'deepseek');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
});

test('structured output uses json object response format', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('{"symbol": "Au"}')]);

    (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'deepseek');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'response_format') === ['type' => 'json_object'];
    });
});

test('structured output appends schema instructions to system message', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('{"symbol": "Au"}')]);

    (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'deepseek');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'JSON object that strictly adheres')
            && str_contains((string)$systemMsg['content'], '"symbol"');
    });
});

test('request without schema excludes response format', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('Hello')]);

    agent()->prompt('Hello', provider: 'deepseek');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('response_format', $body);
    });
});

test('streaming request includes stream options', function (): void {
    aiHttpFake(['*' => aiHttpResponse("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

    $stream = agent()->stream('Hello', provider: 'deepseek');

    foreach ($stream as $event) {
    }

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true
            && Hash::get($body, 'stream_options.include_usage') === true;
    });
});

test('request sends bearer token authorization', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('Hello')]);

    agent()->prompt('Hello', provider: 'deepseek');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('response text is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('CakePHP is great')]);

    $response = agent()->prompt('Tell me about CakePHP', provider: 'deepseek');

    expect($response->text)->toBe('CakePHP is great')
        ->and($response->meta->provider)->toBe('deepseek');
});

test('response usage is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'deepseek-chat',
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

    $response = agent()->prompt('Hello', provider: 'deepseek');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('response usage includes cache hit and reasoning tokens', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-reasoner-1',
        'object' => 'chat.completion',
        'model' => 'deepseek-reasoner',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'The answer is 4.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'prompt_cache_hit_tokens' => 20,
            'prompt_cache_miss_tokens' => 80,
            'total_tokens' => 150,
            'completion_tokens_details' => [
                'reasoning_tokens' => 15,
            ],
        ],
    ])]);

    $response = agent()->prompt('What is 2+2?', provider: 'deepseek', model: 'deepseek-reasoner');

    expect($response->usage->promptTokens)->toBe(80)
        ->and($response->usage->completionTokens)->toBe(50)
        ->and($response->usage->cacheReadInputTokens)->toBe(20)
        ->and($response->usage->cacheWriteInputTokens)->toBe(0)
        ->and($response->usage->reasoningTokens)->toBe(15);
});

test('reasoning content from deepseek-reasoner is ignored, only content surfaces', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-reasoner-1',
        'object' => 'chat.completion',
        'model' => 'deepseek-reasoner',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'reasoning_content' => 'Let me think... 2+2 = 4',
                'content' => 'The answer is 4.',
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 5,
            'completion_tokens' => 3,
        ],
    ])]);

    $response = agent()->prompt('What is 2+2?', provider: 'deepseek', model: 'deepseek-reasoner');

    expect($response->text)->toBe('The answer is 4.');
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    aiHttpFake(['*' => fakeDeepSeekResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
        provider: 'deepseek',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();
        $imageBlock = collect($userMsg['content'])->filter(fn($m): bool => ($m['type'] ?? null) === 'image_url')->first();

        return $imageBlock !== null
            && str_starts_with((string)$imageBlock['image_url']['url'], 'data:image/png;base64,')
            && ! str_contains((string)$imageBlock['image_url']['url'], 'data:;base64,');
    });
});
