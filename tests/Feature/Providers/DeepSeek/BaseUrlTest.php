<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    $this->customUrl = 'http://localhost:1234/v1';
});

test('deepseek text requests use the configured base url', function (): void {
    configureDeepSeekProvider($this->customUrl);

    aiHttpFake([
        '*' => fakeDeepSeekResponse('Hello from local model'),
    ]);

    $response = agent()->prompt('Hello', provider: 'deepseek');

    expect($response->text)->toBe('Hello from local model');

    aiAssertHttpSentCount(1);
    deepseekAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
});

test('deepseek requests fall back to the default base url', function (): void {
    configureDeepSeekProvider();

    aiHttpFake([
        '*' => fakeDeepSeekResponse('Hello from DeepSeek'),
    ]);

    $response = agent()->prompt('Hello', provider: 'deepseek');

    expect($response->text)->toBe('Hello from DeepSeek');

    aiAssertHttpSentCount(1);
    deepseekAssertRequestSent('POST', 'https://api.deepseek.com/v1/chat/completions');
});

function configureDeepSeekProvider(?string $url = null): void
{
    Configure::write('Ai.providers.deepseek', array_filter([

        ...(array)Configure::read('Ai.providers.deepseek'),
        'key' => 'test-key',
        'url' => $url,
    ]));
}

function deepseekAssertRequestSent(string $method, string $url): void
{
    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === $method
        && $request->url() === $url);
}
