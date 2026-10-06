<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\AttributeAgent;
use Crustum\Ai\Test\Fixtures\Agents\AttributeToolChoiceAgent;
use Crustum\Ai\Test\Fixtures\Agents\StructuredAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolChoiceAgent;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.cohere', [
        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
    ]);
});

test('request is sent to the chat endpoint with model and messages', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'cohere', model: 'command-r7b-12-2024');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.cohere.com/v2/chat'
            && $body['model'] === 'command-r7b-12-2024'
            && collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user' && ($m['content'] ?? null) === 'Hi there')->count() > 0;
    });
});

test('default text model is used when none is given', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'cohere');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model'] === 'command-a-03-2025');
});

test('system instructions are sent as system message', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    (new AssistantAgent())->prompt('Hello', provider: 'cohere');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $systemMsg = collect(json_decode($request->body(), true)['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'helpful assistant');
    });
});

test('generation options are mapped when set via attributes', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    (new AttributeAgent())->prompt('Hello', provider: 'cohere');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'temperature') === 0.7
            && Hash::get($body, 'max_tokens') === 4096
            && Hash::get($body, 'p') === 0.8
            && !array_key_exists('top_p', $body);
    });
});

test('generation options are excluded when not set', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'cohere');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return !array_key_exists('temperature', $body)
            && !array_key_exists('max_tokens', $body)
            && !array_key_exists('p', $body);
    });
});

test('tools are sent without a tool choice by default', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('42')]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a number', provider: 'cohere');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return !array_key_exists('tool_choice', $body)
            && $body['tools'][0]['type'] === 'function'
            && $body['tools'][0]['function']['name'] === 'RandomNumberGenerator';
    });
});

test('request without tools excludes tool fields', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'cohere');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return !array_key_exists('tools', $body)
            && !array_key_exists('tool_choice', $body);
    });
});

test('required tool choice is sent in uppercase', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('42')]);

    (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'cohere');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'REQUIRED');
});

test('required tool choice can be set via attribute', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('42')]);

    (new AttributeToolChoiceAgent())->prompt('Give me a number', provider: 'cohere');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'REQUIRED');
});

test('none tool choice is sent in uppercase', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Sure')]);

    (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'cohere');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['tool_choice'] === 'NONE');
});

test('named tool choice only offers that tool and requires a call', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('42')]);

    (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'cohere');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'REQUIRED'
            && count($body['tools']) === 1
            && $body['tools'][0]['function']['name'] === 'custom_named_tool';
    });
});

test('named tool choice for an unknown tool throws', function (): void {
    aiHttpFake();

    (new ToolChoiceAgent(['tool' => 'missing_tool']))->prompt('Give me a number', provider: 'cohere');
})->throws(InvalidArgumentException::class, 'Tool choice [missing_tool] does not match any of the available tools.');

test('structured output sends a json object response format', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('{"symbol":"Au"}')]);

    (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'cohere');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $format = Hash::get(json_decode($request->body(), true), 'response_format');

        return $format['type'] === 'json_object'
            && $format['json_schema']['type'] === 'object'
            && $format['json_schema']['required'] === ['symbol']
            && !array_key_exists('name', $format['json_schema']);
    });
});

test('schema combined with tools omits response format but keeps schema instructions', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('{"number": 42}')]);

    agent(
        tools: [new RandomNumberGenerator()],
        schema: fn($s): array => ['number' => $s->integer()->required()],
    )->prompt('Give me a number', provider: 'cohere');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return !array_key_exists('response_format', $body)
            && is_array($body['tools'])
            && $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'JSON object that strictly adheres');
    });
});

test('streaming request enables streaming without stream options', function (): void {
    aiHttpFake(['*' => $this->fakeStreamResponse($this->streamTextEvents('Hi'))]);

    $this->collectStreamEvents();

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true
            && !array_key_exists('stream_options', $body);
    });
});

test('response text is correctly parsed', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Your name is Sam.')]);

    $response = agent()->prompt('What is my name?', provider: 'cohere', model: 'command-a-03-2025');

    expect($response->text)->toBe('Your name is Sam.')
        ->and($response->meta->provider)->toBe('cohere')
        ->and($response->meta->model)->toBe('command-a-03-2025');
});

test('response text joins multiple text blocks', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse([
        ['type' => 'text', 'text' => 'Hello'],
        ['type' => 'text', 'text' => ' world'],
    ])]);

    expect(agent()->prompt('Hi', provider: 'cohere')->text)->toBe('Hello world');
});

test('response usage reports token counts and cached tokens', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    $response = agent()->prompt('Hello', provider: 'cohere');

    expect($response->usage->inputTokens)->toBe(557)
        ->and($response->usage->outputTokens)->toBe(8)
        ->and($response->usage->cacheReadInputTokens)->toBe(480);
});

test('response usage leaves cached tokens null when absent', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        'finish_reason' => 'COMPLETE',
        'usage' => ['tokens' => ['input_tokens' => 557, 'output_tokens' => 8]],
    ])]);

    expect(agent()->prompt('Hello', provider: 'cohere')->usage->cacheReadInputTokens)->toBeNull();
});

test('finish reasons are mapped', function (string $cohereReason, FinishReason $expected): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello', $cohereReason)]);

    $response = agent()->prompt('Hello', provider: 'cohere');

    expect($response->steps->last()->finishReason)->toBe($expected);
})->with([
    ['COMPLETE', FinishReason::Stop],
    ['STOP_SEQUENCE', FinishReason::Stop],
    ['MAX_TOKENS', FinishReason::Length],
    ['ERROR', FinishReason::Error],
    ['TIMEOUT', FinishReason::Error],
    ['SOMETHING_NEW', FinishReason::Unknown],
]);

test('structured response is correctly parsed', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('{"symbol":"Au"}')]);

    $response = (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'cohere');

    expect($response->structured['symbol'])->toBe('Au');
});
