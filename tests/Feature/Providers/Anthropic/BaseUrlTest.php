<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

test('anthropic requests use the configured base url', function (): void {
    Configure::write('Ai.providers.anthropic.url', 'https://custom-proxy.example.com/v1');

    aiHttpFake([
        'custom-proxy.example.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'anthropic',
    );

    aiAssertHttpSent(fn($request): bool => $request->url() === 'https://custom-proxy.example.com/v1/messages');
});

test('anthropic requests fall back to the default base url', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'anthropic',
    );

    aiAssertHttpSent(fn($request): bool => $request->url() === 'https://api.anthropic.com/v1/messages');
});
