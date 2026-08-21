<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    $this->customUrl = 'http://localhost:1234/v1';
});

test('xai text requests use the configured base url', function (): void {
    Configure::write('Ai.providers.xai', array_filter([

        ...(array)Configure::read('Ai.providers.xai'),
        'key' => 'test-key',
        'url' => $this->customUrl,
    ]));

    aiHttpFake([
        '*' => aiHttpResponse(fakeXaiBaseUrlResponseData('Hello from local model')),
    ]);

    $response = agent()->prompt('Hello', provider: 'xai');

    expect($response->text)->toBe('Hello from local model');

    aiAssertHttpSentCount(1);
    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === "{$this->customUrl}/responses");
});

test('xai requests fall back to the default base url', function (): void {
    Configure::write('Ai.providers.xai', array_filter([

        ...(array)Configure::read('Ai.providers.xai'),
        'key' => 'test-key',
    ]));

    aiHttpFake([
        '*' => aiHttpResponse(fakeXaiBaseUrlResponseData('Hello from xAI')),
    ]);

    $response = agent()->prompt('Hello', provider: 'xai');

    expect($response->text)->toBe('Hello from xAI');

    aiAssertHttpSentCount(1);
    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.x.ai/v1/responses');
});

function fakeXaiBaseUrlResponseData(string $text): array
{
    return [
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ];
}
