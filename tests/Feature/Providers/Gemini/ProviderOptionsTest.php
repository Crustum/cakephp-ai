<?php
declare(strict_types=1);

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Crustum\Ai\Trait\PromptableTrait;

test('provider options are included in generation config', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new ProviderOptionsAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );

    aiAssertHttpSent(function ($request): bool {
        $body = $request->data();
        $config = $body['generationConfig'] ?? [];

        return isset($config['thinkingConfig'])
            && $config['thinkingConfig']['thinkingBudget'] === 10000;
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );

    aiAssertHttpSent(function ($request): bool {
        $config = $request->data()['generationConfig'] ?? [];

        return ! isset($config['thinkingConfig']);
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ProviderOptionsWithToolsAgent())->prompt(
        'Generate a random number',
        provider: 'gemini',
    );

    expect($response->text)->toBe('The number is 72019');

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $firstConfig = $recorded[0][0]->data()['generationConfig'] ?? [];
    expect($firstConfig['thinkingConfig']['thinkingBudget'])->toBe(10000);

    $secondConfig = $recorded[1][0]->data()['generationConfig'] ?? [];
    expect($secondConfig)->toHaveKey('thinkingConfig')
        ->and($secondConfig['thinkingConfig']['thinkingBudget'])->toBe(10000);
});

test('cachedContent is placed at top level of request body, not in generationConfig', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

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
                Lab::Gemini => ['cachedContent' => 'cachedContents/test-cache-123'],
                default => [],
            };
        }
    };

    $agent->prompt('Hi', provider: 'gemini');

    aiAssertHttpSent(function ($request): bool {
        $body = $request->data();

        return isset($body['cachedContent'])
            && $body['cachedContent'] === 'cachedContents/test-cache-123'
            && ! isset($body['generationConfig']['cachedContent']);
    });
});
