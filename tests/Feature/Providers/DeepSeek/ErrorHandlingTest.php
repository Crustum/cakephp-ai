<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Exception\InsufficientCreditsException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.deepseek', [

        ...(array)Configure::read('Ai.providers.deepseek'),
        'key' => 'test-key',
    ]);
});

test('http error response throws request exception', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'max_tokens: must be at least 1',
            ],
        ], 400),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'deepseek',
    );
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'deepseek',
    );
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'deepseek',
    );
})->throws(ProviderOverloadedException::class);

test('402 response throws insufficient credits exception', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse([
            'error' => [
                'message' => 'Insufficient Balance',
                'type' => 'insufficient_balance_error',
                'param' => null,
                'code' => 'insufficient_balance',
            ],
        ], 402),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'deepseek',
    );
})->throws(InsufficientCreditsException::class);

test('error in 200 response throws ai exception', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse([
            'error' => [
                'type' => 'api_error',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'deepseek',
    );
})->throws(AiException::class, 'DeepSeek Error');
