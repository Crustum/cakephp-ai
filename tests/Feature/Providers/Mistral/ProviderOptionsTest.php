<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.mistral', [

        ...(array)Configure::read('Ai.providers.mistral'),
        'key' => 'test-key',
    ]);
});

test('provider options are included in mistral request body', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    (new ProviderOptionsAgent())->prompt('Hello', provider: 'mistral');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'frequency_penalty') === 0.5
            && Hash::get($body, 'presence_penalty') === 0.3;
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'mistral');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent())->prompt('Give me a number', provider: 'mistral');

    $requests = aiHttpRecorded(fn(AiHttpRequest $r): true => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode((string)$requests[1][0]->body(), true);

    expect(Hash::get($followUpBody, 'frequency_penalty'))->toBe(0.5);
});
