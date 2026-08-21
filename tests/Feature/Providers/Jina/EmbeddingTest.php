<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.jina', [

        ...(array)Configure::read('Ai.providers.jina'),
        'key' => 'test-key',
    ]);
});

test('embeddings request includes model, input, and dimensions', function (): void {
    aiHttpFake(['*' => fakeJinaEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->dimensions(1024)->generate(provider: 'jina', model: 'jina-embeddings-v4');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'jina-embeddings-v4'
            && $body['input'][0]['text'] === 'Hello world'
            && $body['dimensions'] === 1024
            && $body['task'] === 'retrieval.passage'
            && $request->url() === 'https://api.jina.ai/v1/embeddings';
    });
});

test('embeddings response is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeJinaEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('jina')
        ->and($response->meta->model)->toBe('jina-embeddings-v4');
});

test('embeddings request sends bearer token', function (): void {
    aiHttpFake(['*' => fakeJinaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings use default model when none specified', function (): void {
    aiHttpFake(['*' => fakeJinaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'jina');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model'] === 'jina-embeddings-v4');
});

test('embeddings throw when the API returns an error', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['detail' => 'unauthorized'], 401)]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
})->throws(RequestException::class);

test('multiple inputs return multiple embeddings', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'model' => 'jina-embeddings-v4',
        'object' => 'list',
        'usage' => ['total_tokens' => 20],
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6]);
});

test('embeddings default to 2048 dimensions when none specified', function (): void {
    aiHttpFake(['*' => fakeJinaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['dimensions'] === 2048);
});

test('embeddings request includes provider options and overrides default task', function (): void {
    aiHttpFake(['*' => fakeJinaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(['task' => 'retrieval.query', 'late_chunking' => true])
        ->generate(provider: 'jina', model: 'jina-embeddings-v4');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['task'] === 'retrieval.query'
            && $body['late_chunking'] === true
            && $body['model'] === 'jina-embeddings-v4';
    });
});

function fakeJinaEmbeddingsResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'model' => 'jina-embeddings-v4',
        'object' => 'list',
        'usage' => ['total_tokens' => 10],
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
    ]);
}
