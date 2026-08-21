<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.cohere', [

        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
    ]);
});

test('embeddings request includes model, texts, input_type, and embedding_types', function (): void {
    aiHttpFake(['*' => fakeCohereEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->generate(provider: 'cohere', model: 'embed-v4.0');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'embed-v4.0'
            && $body['texts'] === ['Hello world']
            && $body['input_type'] === 'search_document'
            && $body['embedding_types'] === ['float']
            && $request->url() === 'https://api.cohere.com/v2/embed';
    });
});

test('embeddings response is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeCohereEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'cohere', model: 'embed-v4.0');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('cohere')
        ->and($response->meta->model)->toBe('embed-v4.0');
});

test('embeddings request sends bearer token', function (): void {
    aiHttpFake(['*' => fakeCohereEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings use default model when none specified', function (): void {
    aiHttpFake(['*' => fakeCohereEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model'] === 'embed-v4.0');
});

test('multiple inputs return multiple embeddings', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'embeddings' => ['float' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]],
        'meta' => ['billed_units' => ['input_tokens' => 20]],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'cohere', model: 'embed-v4.0');

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6]);
});

test('embeddings request includes provider options and lets them override default input_type', function (): void {
    aiHttpFake(['*' => fakeCohereEmbeddingsResponse()]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(['input_type' => 'search_query', 'truncate' => 'END'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['input_type'] === 'search_query'
            && $body['truncate'] === 'END'
            && $body['model'] === 'embed-v4.0'
            && $body['texts'] === ['Hello'];
    });
});

test('provider options cannot override framework controlled keys', function (): void {
    aiHttpFake(['*' => fakeCohereEmbeddingsResponse()]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(['model' => 'hijacked', 'texts' => ['hijacked']])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'embed-v4.0' && $body['texts'] === ['Hello'];
    });
});

test('embeddings throw when the API returns an error', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['message' => 'unauthorized'], 401)]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
})->throws(RequestException::class);

function fakeCohereEmbeddingsResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
        'meta' => ['billed_units' => ['input_tokens' => 10]],
    ]);
}
