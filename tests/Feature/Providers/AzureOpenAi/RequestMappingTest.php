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
    Configure::write('Ai.providers.azure', [

        ...(array)Configure::read('Ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]);
});

test('request includes model and input', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'azure', model: 'gpt-4o');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-4o'
            && is_array($body['input'])
            && collect($body['input'])->some(fn($m): bool => $m['role'] === 'user'
                && collect($m['content'])->some(fn($c): bool => ($c['text'] ?? '') === 'Hi there'));
    });
});

test('system instructions are sent as system message in input', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('Hello')]);

    (new AssistantAgent())->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('Hello')]);

    (new AttributeAgent())->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'temperature') === 0.7
            && Hash::get($body, 'max_output_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_output_tokens', $body);
    });
});

test('tools include tool choice auto', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('42')]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a number', provider: 'azure');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && $body['tools'] !== [];
    });
});

test('request without tools excludes tool fields', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('structured output includes json schema text format', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('{"symbol": "Au"}')]);

    (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'azure');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $format = Hash::get($body, 'text.format');

        return ($format['type'] ?? '') === 'json_schema'
            && isset($format['name'])
            && isset($format['schema'])
            && $format['strict'] === true;
    });
});

test('request without schema excludes text format', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('text', $body);
    });
});

test('streaming request includes stream flag', function (): void {
    aiHttpFake(['*' => aiHttpResponse(
        body: "data: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_1\",\"model\":\"gpt-4o\",\"status\":\"in_progress\",\"output\":[]}}\n\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Hi\",\"item_id\":\"msg_1\",\"output_index\":0,\"content_index\":0}\n\ndata: {\"type\":\"response.output_text.done\",\"text\":\"Hi\",\"item_id\":\"msg_1\",\"output_index\":0,\"content_index\":0}\n\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_1\",\"model\":\"gpt-4o\",\"status\":\"completed\",\"output\":[{\"type\":\"message\",\"status\":\"completed\",\"role\":\"assistant\",\"content\":[{\"type\":\"output_text\",\"text\":\"Hi\"}]}],\"usage\":{\"input_tokens\":1,\"output_tokens\":1,\"input_tokens_details\":{\"cached_tokens\":0},\"output_tokens_details\":{\"reasoning_tokens\":0}}}}\n\n",
    )]);

    $stream = agent()->stream('Hello', provider: 'azure');

    foreach ($stream as $event) {
    }

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true;
    });
});

test('request sends api-key header authentication', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('api-key', 'test-key'));
});

test('request does not include api-version query parameter', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hello', provider: 'azure');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => ! str_contains($request->url(), 'api-version'));
});

test('response text is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('CakePHP is great')]);

    $response = agent()->prompt('Tell me about CakePHP', provider: 'azure');

    expect($response->text)->toBe('CakePHP is great')
        ->and($response->meta->provider)->toBe('azure');
});

test('response usage is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'resp_azure_123',
        'status' => 'completed',
        'model' => 'gpt-4o',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => 'Hello']],
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'azure');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('structured response is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeAzureResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent())->prompt('What is the symbol for Gold?', provider: 'azure');

    expect($response->structured['symbol'])->toBe('Au');
});
