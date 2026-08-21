<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.xai', [

        ...(array)Configure::read('Ai.providers.xai'),
        'key' => 'test-key',
    ]);
});

test('http error response throws request exception', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid API key',
            ],
        ], 401),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: 'xai');
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: 'xai');
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: 'xai');
})->throws(ProviderOverloadedException::class);

test('error in 200 response throws ai exception', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'error' => [
                'type' => 'server_error',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: 'xai');
})->throws(AiException::class, 'xAI Error');

test('failed status response throws ai exception', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'failed',
            'error' => [
                'code' => 'server_error',
                'message' => 'The response failed.',
            ],
            'output' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ], 200),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: 'xai');
})->throws(AiException::class, 'The response failed.');
