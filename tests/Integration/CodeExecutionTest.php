<?php
declare(strict_types=1);

use Crustum\Ai\Providers\Tools\CodeExecution;
use Crustum\Ai\Streaming\Event\ProviderToolEvent;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Test\Support\Skips\ApiKey;

test('agents answer using the hosted code execution tool', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $response = agent(
        'Use the code execution tool to compute the answer. Reply with the hex digest only.',
        tools: [new CodeExecution()],
    )->prompt(
        'What is the SHA-256 hex digest of the exact ASCII string crustum-ai-code-execution?',
        provider: $provider,
        model: $model,
    );

    expect($response->text)->toContain('f9f1adefdcca1a8f9133e51e5a64aaccfd6171271cea970ac160b035b1fcc775');
})->with('code-execution-providers');

test('agents stream provider tool events while using the hosted code execution tool', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $response = agent(
        'Use the code execution tool to compute the answer. Reply with the hex digest only.',
        tools: [new CodeExecution()],
    )->stream(
        'What is the SHA-256 hex digest of the exact ASCII string crustum-ai-code-execution?',
        provider: $provider,
        model: $model,
    );

    $providerEvents = [];
    $text = '';

    foreach ($response as $event) {
        if ($event instanceof ProviderToolEvent) {
            $providerEvents[] = $event;
        } elseif ($event instanceof TextDelta) {
            $text .= $event->delta;
        }
    }

    $streamedCode = array_filter($providerEvents, fn(ProviderToolEvent $event): bool => str_contains(json_encode($event->data), 'sha256'));

    expect($streamedCode)->not->toBeEmpty()
        ->and($providerEvents[0]->provider)->toBe($provider)
        ->and($text)->toContain('f9f1adefdcca1a8f9133e51e5a64aaccfd6171271cea970ac160b035b1fcc775');
})->with('code-execution-providers');
