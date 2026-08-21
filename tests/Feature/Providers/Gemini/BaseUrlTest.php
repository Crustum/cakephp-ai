<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

test('gemini requests use the configured base url', function (): void {
    Configure::write('Ai.providers.gemini.url', 'https://custom-proxy.example.com/v1');

    aiHttpFake([
        'custom-proxy.example.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );

    aiAssertHttpSent(fn($request): bool => str_contains((string)$request->url(), 'https://custom-proxy.example.com/v1'));
});

test('gemini requests fall back to the default base url', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );

    aiAssertHttpSent(fn($request): bool => str_contains((string)$request->url(), 'generativelanguage.googleapis.com'));
});
