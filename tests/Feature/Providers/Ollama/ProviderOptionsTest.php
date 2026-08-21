<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Fixtures\Agents\OllamaStructuredProviderOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\OllamaTopLevelOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.ollama.key', '');
});

test('provider options are included in ollama options object', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse('Hello'),
    ]);

    (new ProviderOptionsAgent())->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return array_key_exists('options', $body);
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse('Hello'),
    ]);

    agent()->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return !array_key_exists('options', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent())->prompt('Give me a number', provider: 'ollama');

    $requests = aiHttpRecorded(fn(AiHttpRequest $r): true => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);

    expect(array_key_exists('options', $followUpBody))->toBeTrue();
});

test('top-level provider options are placed at the body root, not inside options', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    (new OllamaTopLevelOptionsAgent())->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ($body['format'] ?? null) === 'json'
            && ($body['keep_alive'] ?? null) === '10m'
            && ($body['logprobs'] ?? null) === true
            && !array_key_exists('format', $body['options'] ?? [])
            && !array_key_exists('keep_alive', $body['options'] ?? [])
            && !array_key_exists('logprobs', $body['options'] ?? []);
    });
});

test('model parameters from provider options remain inside the options object', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    (new OllamaTopLevelOptionsAgent())->prompt('Hello', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ($body['options']['num_ctx'] ?? null) === 8192
            && !array_key_exists('num_ctx', $body);
    });
});

test('structured output schema is not overwritten by a provider options format', function (): void {
    aiHttpFake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

    (new OllamaStructuredProviderOptionsAgent())->prompt('What is the symbol for Gold?', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return array_key_exists('format', $body)
            && is_array($body['format']);
    });
});
