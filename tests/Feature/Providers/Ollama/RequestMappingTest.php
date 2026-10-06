<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\AttributeAgent;
use Crustum\Ai\Test\Fixtures\Agents\StructuredAgent;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.ollama.key', '');
});

test('request includes model and messages', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'ollama', model: 'llama3.1:8b');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'llama3.1:8b'
            && count($body['messages']) >= 1
            && collect($body['messages'])->some(fn($m): bool => ($m['role'] ?? null) === 'user' && ($m['content'] ?? null) === 'Hi there');
    });
});

test('system instructions are sent as system message', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    (new AssistantAgent())->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are sent in options object', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    (new AttributeAgent())->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'options.temperature') === 0.7
            && Hash::get($body, 'options.num_predict') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return !array_key_exists('options', $body)
            || (!array_key_exists('temperature', $body['options'] ?? [])
                && !array_key_exists('num_predict', $body['options'] ?? []));
    });
});

test('tools are included in the request', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('42')]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a number', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return is_array($body['tools'])
            && $body['tools'] !== [];
    });
});

test('request without tools excludes tools field', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return !array_key_exists('tools', $body);
    });
});

test('structured output includes format field', function (): void {
    aiHttpFake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

    (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return array_key_exists('format', $body)
            && is_array($body['format']);
    });
});

test('structured output appends json schema instruction to system message', function (): void {
    aiHttpFake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

    (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMessage = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return $systemMessage !== null
            && str_contains((string)$systemMessage['content'], 'You MUST respond EXCLUSIVELY with a JSON object that strictly adheres to the following schema')
            && str_contains((string)$systemMessage['content'], '"symbol"');
    });
});

test('request without schema excludes format field', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return !array_key_exists('format', $body);
    });
});

test('non-streaming request sets stream to false', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === false;
    });
});

test('response text is correctly parsed', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('CakePHP is great')]);

    $response = agent()->prompt('Tell me about CakePHP', provider: 'ollama');

    expect($response->text)->toBe('CakePHP is great')
        ->and($response->meta->provider)->toBe('ollama');
});

test('response usage is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'model' => 'llama3.1:8b',
        'message' => ['role' => 'assistant', 'content' => 'Hello'],
        'done_reason' => 'stop',
        'done' => true,
        'prompt_eval_count' => 10,
        'eval_count' => 5,
    ])]);

    $response = agent()->prompt('Hello', provider: 'ollama');

    expect($response->usage->inputTokens)->toBe(10)
        ->and($response->usage->outputTokens)->toBe(5);
});

test('response usage reports cached prompt tokens', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'model' => 'llama3.1:8b',
        'message' => ['role' => 'assistant', 'content' => 'Hello'],
        'done_reason' => 'stop',
        'done' => true,
        'prompt_eval_count' => 100,
        'prompt_eval_cached_count' => 80,
        'eval_count' => 5,
    ])]);

    $response = agent()->prompt('Hello', provider: 'ollama');

    expect($response->usage->inputTokens)->toBe(100)
        ->and($response->usage->cacheReadInputTokens)->toBe(80)
        ->and($response->usage->uncachedInputTokens())->toBe(20);
});

test('structured response is correctly parsed', function (): void {
    aiHttpFake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'ollama');

    expect($response->structured['symbol'])->toBe('Au');
});

test('request is sent to the chat endpoint', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_ends_with($request->url(), '/api/chat'));
});
