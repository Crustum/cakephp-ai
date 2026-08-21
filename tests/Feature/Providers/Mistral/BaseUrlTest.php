<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.mistral', [

        ...(array)Configure::read('Ai.providers.mistral'),
        'key' => 'test-key',
    ]);

    $this->customUrl = 'http://localhost:1234/v1';
});

test('mistral requests use the configured base url', function (): void {
    configureMistralProvider($this->customUrl);

    aiHttpFake([
        '*' => $this->fakeTextResponse('Hello from custom'),
    ]);

    $response = agent()->prompt('Hello', provider: 'mistral');

    expect($response->text)->toBe('Hello from custom');

    aiAssertHttpSentCount(1);
    mistralAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
});

test('mistral requests fall back to the default base url', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse('Hello from Mistral'),
    ]);

    $response = agent()->prompt('Hello', provider: 'mistral');

    expect($response->text)->toBe('Hello from Mistral');

    aiAssertHttpSentCount(1);
    mistralAssertRequestSent('POST', 'https://api.mistral.ai/v1/chat/completions');
});

function configureMistralProvider(?string $url = null): void
{
    Configure::write('Ai.providers.mistral', array_filter([

        ...(array)Configure::read('Ai.providers.mistral'),
        'key' => 'test-key',
        'url' => $url,
    ]));
}

function mistralAssertRequestSent(string $method, string $url): void
{
    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === $method
        && $request->url() === $url);
}
