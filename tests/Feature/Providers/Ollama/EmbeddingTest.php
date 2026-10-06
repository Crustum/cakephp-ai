<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Http\Client\Exception\ClientException;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.ollama.key', '');
    Configure::write('Ai.providers.ollama.url', 'http://localhost:11434');
});

test('embeddings request includes model and input', function (): void {
    aiHttpFake(['*' => fakeOllamaEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'nomic-embed-text'
            && $body['input'] === ['Hello world']
            && str_ends_with($request->url(), '/api/embed');
    });
});

test('embeddings response is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeOllamaEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toHaveCount(3)
        ->and($response->usage->inputTokens)->toBe(10)
        ->and($response->meta->provider)->toBe('ollama');
});

test('multiple inputs return multiple embeddings', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'model' => 'nomic-embed-text',
        'embeddings' => [
            [0.1, 0.2, 0.3],
            [0.4, 0.5, 0.6],
        ],
        'prompt_eval_count' => 20,
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    expect($response->embeddings)->toHaveCount(2);
});

test('embeddings request sends bearer token when key is set', function (): void {
    Configure::write('Ai.providers.ollama.key', 'test-key');

    aiHttpFake(['*' => fakeOllamaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings request sends no authorization header when key is empty', function (): void {
    aiHttpFake(['*' => fakeOllamaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => !$request->hasHeader('Authorization'));
});

test('embeddings rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'http://localhost:11434/*' => aiHttpResponse([
            'error' => 'rate limit exceeded',
        ], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'http://localhost:11434/*' => aiHttpResponse([
            'error' => 'server overloaded',
        ], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function (): void {
    aiHttpFake([
        'http://localhost:11434/*' => aiHttpResponse([
            'error' => 'model not found',
        ], 400),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');
})->throws(ClientException::class);

test('embeddings request includes provider options in the request body', function (): void {
    aiHttpFake(['*' => fakeOllamaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(['truncate' => false, 'keep_alive' => '5m'])
        ->generate(provider: 'ollama', model: 'nomic-embed-text');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['truncate'] === false
            && $body['keep_alive'] === '5m'
            && $body['model'] === 'nomic-embed-text';
    });
});

function fakeOllamaEmbeddingsResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'model' => 'nomic-embed-text',
        'embeddings' => [
            [0.1, 0.2, 0.3],
        ],
        'prompt_eval_count' => 10,
    ]);
}
