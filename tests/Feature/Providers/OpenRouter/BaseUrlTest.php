<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    $this->customUrl = 'http://localhost:1234/v1';
});

test('openrouter text requests use the configured base url', function (): void {
    configureOpenRouterProvider($this->customUrl);

    aiHttpFake(['*' => fakeOpenRouterResponse('Hello from local model')]);

    $response = agent()->prompt('Hello', provider: 'openrouter');

    expect($response->text)->toBe('Hello from local model');

    aiAssertHttpSentCount(1);
    openRouterAssertRequestSent('POST', $this->customUrl . '/chat/completions');
});

test('openrouter requests fall back to the default base url', function (): void {
    configureOpenRouterProvider();

    aiHttpFake(['*' => fakeOpenRouterResponse('Hello from OpenRouter')]);

    $response = agent()->prompt('Hello', provider: 'openrouter');

    expect($response->text)->toBe('Hello from OpenRouter');

    aiAssertHttpSentCount(1);
    openRouterAssertRequestSent('POST', 'https://openrouter.ai/api/v1/chat/completions');
});

function configureOpenRouterProvider(?string $url = null): void
{
    Configure::write('Ai.providers.openrouter.key', 'test-key');
    if ($url !== null) {
        Configure::write('Ai.providers.openrouter.url', $url);
    }
}

function openRouterAssertRequestSent(string $method, string $url): void
{
    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === $method
        && $request->url() === $url);
}
