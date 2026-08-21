<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Http\Client\Exception\ClientException;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.ollama.key', '');
    Configure::write('Ai.providers.ollama.url', 'http://localhost:11434');
});

test('http error response throws request exception', function (): void {
    aiHttpFake([
        'http://localhost:11434/*' => aiHttpResponse([
            'error' => 'model not found',
        ], 400),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'ollama',
    );
})->throws(ClientException::class);

test('rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'http://localhost:11434/*' => aiHttpResponse([
            'error' => 'rate limit exceeded',
        ], 429),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'ollama',
    );
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'http://localhost:11434/*' => aiHttpResponse([
            'error' => 'server overloaded',
        ], 503),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'ollama',
    );
})->throws(ProviderOverloadedException::class);

test('error in 200 response throws ai exception', function (): void {
    aiHttpFake([
        'http://localhost:11434/*' => aiHttpResponse([
            'error' => 'model "unknown-model" not found',
        ], 200),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'ollama',
    );
})->throws(AiException::class, 'Ollama Error');
