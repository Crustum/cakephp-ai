<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Exception\InsufficientCreditsException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

test('http error response throws request exception', function (): void {
    aiHttpFake(['openrouter.ai/*' => aiHttpResponse(['error' => ['message' => 'Bad request']], 400)]);

    (new AssistantAgent())->prompt('Hello', provider: 'openrouter');
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function (): void {
    aiHttpFake(['openrouter.ai/*' => aiHttpResponse(['error' => ['message' => 'Rate limited']], 429)]);

    (new AssistantAgent())->prompt('Hello', provider: 'openrouter');
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    (new AssistantAgent())->prompt('Hello', provider: 'openrouter');
})->throws(ProviderOverloadedException::class);

test('402 response throws insufficient credits exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'code' => 402,
                'message' => 'Insufficient credits. Add more credits at https://openrouter.ai/credits',
            ],
        ], 402),
    ]);

    (new AssistantAgent())->prompt('Hello', provider: 'openrouter');
})->throws(InsufficientCreditsException::class);

test('error in 200 response throws ai exception', function (): void {
    aiHttpFake(['openrouter.ai/*' => aiHttpResponse([
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'The model does not exist.',
        ],
    ])]);

    (new AssistantAgent())->prompt('Hello', provider: 'openrouter');
})->throws(AiException::class, 'OpenRouter Error');
