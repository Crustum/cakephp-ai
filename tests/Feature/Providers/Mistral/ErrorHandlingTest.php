<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.mistral', [

        ...(array)Configure::read('Ai.providers.mistral'),
        'key' => 'test-key',
    ]);
});

test('http error response throws request exception', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'object' => 'error',
            'message' => 'Invalid API key',
            'type' => 'authentication_error',
        ], 401),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: 'mistral');
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'object' => 'error',
            'message' => 'Rate limit exceeded',
            'type' => 'rate_limit_error',
        ], 429),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: 'mistral');
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'object' => 'error',
            'message' => 'The server is currently overloaded. Please try again later.',
            'type' => 'server_error',
        ], 503),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: 'mistral');
})->throws(ProviderOverloadedException::class);

test('error in 200 response throws ai exception', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'object' => 'error',
            'error' => [
                'type' => 'server_error',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: 'mistral');
})->throws(AiException::class, 'Mistral Error');
