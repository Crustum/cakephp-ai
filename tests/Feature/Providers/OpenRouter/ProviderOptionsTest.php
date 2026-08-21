<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Trait\PromptableTrait;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

test('provider options are included in openrouter request body', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    (new ProviderOptionsAgent())->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'frequency_penalty') === 0.5
            && Hash::get($body, 'presence_penalty') === 0.3;
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('frequency_penalty', $body)
            && ! array_key_exists('presence_penalty', $body);
    });
});

test('agents using the docs idiom (no Lab::tryFrom workaround) have provider options reach the openrouter body', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    $agent = new class implements Agent, HasProviderOptions
    {
        use PromptableTrait;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return match ($provider) {
                Lab::OpenRouter => ['reasoning' => ['effort' => 'medium']],
                default => [],
            };
        }
    };

    $agent->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return Hash::get($body, 'reasoning.effort') === 'medium';
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('The number is 72019'),
        ]),
    ]);

    (new ProviderOptionsWithToolsAgent())->prompt('Give me a number', provider: 'openrouter');

    $requests = aiHttpRecorded(fn(AiHttpRequest $r): true => true);

    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);

    expect(Hash::get($followUpBody, 'frequency_penalty'))->toBe(0.5);
});
