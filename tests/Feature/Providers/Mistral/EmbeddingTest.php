<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.mistral', [

        ...(array)Configure::read('Ai.providers.mistral'),
        'key' => 'test-key',
    ]);
});

test('embeddings request includes model and input', function (): void {
    aiHttpFake(['*' => fakeEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->generate(provider: 'mistral', model: 'mistral-embed');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'mistral-embed'
            && $body['input'] === ['Hello world']
            && $request->url() === 'https://api.mistral.ai/v1/embeddings';
    });
});

test('embeddings response is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'mistral', model: 'mistral-embed');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toHaveCount(3)
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('mistral');
});

test('embeddings request sends bearer token', function (): void {
    aiHttpFake(['*' => fakeEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('multiple inputs return multiple embeddings', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'embd-123',
        'object' => 'list',
        'model' => 'mistral-embed',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'usage' => ['total_tokens' => 20],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'mistral', model: 'mistral-embed');

    expect($response->embeddings)->toHaveCount(2);
});

test('embeddings rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'api.mistral.ai/*' => aiHttpResponse([
            'object' => 'error',
            'message' => 'Rate limit exceeded',
            'type' => 'rate_limit_error',
        ], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'api.mistral.ai/*' => aiHttpResponse([
            'object' => 'error',
            'message' => 'The server is currently overloaded.',
            'type' => 'server_error',
        ], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function (): void {
    aiHttpFake([
        'api.mistral.ai/*' => aiHttpResponse([
            'object' => 'error',
            'message' => 'Invalid API key',
            'type' => 'authentication_error',
        ], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');
})->throws(RequestException::class);

test('embeddings request includes provider options in the request body', function (): void {
    aiHttpFake(['*' => fakeEmbeddingsResponse()]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(['output_dimension' => 256, 'output_dtype' => 'float'])
        ->generate(provider: 'mistral', model: 'mistral-embed');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['output_dimension'] === 256
            && $body['output_dtype'] === 'float'
            && $body['model'] === 'mistral-embed';
    });
});

function fakeEmbeddingsResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'embd-123',
        'object' => 'list',
        'model' => 'mistral-embed',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['total_tokens' => 10],
    ]);
}
