<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.cohere', [
        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
    ]);
});

test('provider options are included in cohere request body', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    (new ProviderOptionsAgent())->prompt('Hello', provider: 'cohere');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'k') === 40
            && Hash::get($body, 'safety_mode') === 'CONTEXTUAL';
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'cohere');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return !array_key_exists('k', $body)
            && !array_key_exists('safety_mode', $body);
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent())->prompt('Give me a number', provider: 'cohere');

    $requests = aiHttpRecorded();

    expect($requests)->toHaveCount(2)
        ->and(Hash::get(json_decode((string)$requests[1][0]->body(), true), 'k'))->toBe(40);
});
