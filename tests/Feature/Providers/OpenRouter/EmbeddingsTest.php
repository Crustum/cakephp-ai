<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

test('embeddings request is correctly formatted', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['prompt_tokens' => 5],
    ])]);

    Ai::manager()->provider('openrouter')->embeddings(['Hello world']);

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'google/gemini-embedding-001'
            && $body['input'] === ['Hello world']
            && $body['dimensions'] === 1536
            && str_contains($request->url(), 'embeddings');
    });
});

test('embeddings response is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'usage' => ['prompt_tokens' => 10],
    ])]);

    $response = Ai::manager()->provider('openrouter')->embeddings(['Hello', 'World']);

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6])
        ->and($response->tokens)->toBe(10);
});

test('embeddings request sends bearer token', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::manager()->provider('openrouter')->embeddings(['test']);

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings request uses openrouter base url', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::manager()->provider('openrouter')->embeddings(['test']);

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://openrouter.ai/api/v1/embeddings');
});

test('embeddings request includes provider options in the request body', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::manager()->provider('openrouter')->embeddings(
        inputs: ['Hello'],
        providerOptions: ['encoding_format' => 'base64'],
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['encoding_format'] === 'base64'
            && $body['input'] === ['Hello'];
    });
});

test('embeddings rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
    ]);

    expect(fn() => Ai::manager()->provider('openrouter')->embeddings(['Hello']))
        ->toThrow(RateLimitedException::class);
});

test('embeddings overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse(['error' => ['message' => 'Server overloaded']], 503),
    ]);

    expect(fn() => Ai::manager()->provider('openrouter')->embeddings(['Hello']))
        ->toThrow(ProviderOverloadedException::class);
});

test('embeddings error in 200 response throws ai exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'The model does not exist.',
            ],
        ]),
    ]);

    expect(fn() => Ai::manager()->provider('openrouter')->embeddings(['Hello']))
        ->toThrow(AiException::class, 'OpenRouter Error');
});
