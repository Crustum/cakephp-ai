<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('provider options are included in openai request body', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('Hello'),
    ]);

    (new ProviderOptionsAgent())->prompt('Hello', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'reasoning.effort') === 'high'
            && Hash::get($body, 'frequency_penalty') === 0.5
            && Hash::get($body, 'presence_penalty') === 0.3;
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('Hello'),
    ]);

    agent()->prompt('Hello', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('reasoning', $body)
            && ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            fakeOpenAiToolCallResponse(),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent())->prompt('Give me a number', provider: 'openai');

    $requests = aiHttpRecorded(fn(AiHttpRequest $r): true => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);

    expect(Hash::get($followUpBody, 'reasoning.effort'))->toBe('high')
        ->and(Hash::get($followUpBody, 'frequency_penalty'))->toBe(0.5)
        ->and($followUpBody)->toHaveKey('previous_response_id');
});
