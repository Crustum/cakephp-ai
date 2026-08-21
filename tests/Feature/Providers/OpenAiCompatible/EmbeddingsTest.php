<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Providers\OpenAiCompatibleProvider;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai-compatible', [
        'className' => OpenAiCompatibleProvider::class,
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
        'models' => [
            'text' => ['default' => 'local-model'],
            'embeddings' => ['default' => 'local-embeddings-model', 'dimensions' => 768],
        ],
    ]);
});

test('embeddings request is correctly formatted', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['prompt_tokens' => 5],
    ])]);

    Embeddings::for(['Hello world'])->generate(provider: 'openai-compatible');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'local-embeddings-model'
            && $body['input'] === ['Hello world']
            && $body['dimensions'] === 768
            && $request->url() === 'http://localhost:1234/v1/embeddings';
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

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'openai-compatible');

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('openai-compatible');
});

test('embeddings request sends bearer token', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Embeddings::for(['test'])->generate(provider: 'openai-compatible');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings request omits authorization header when no key is configured', function (): void {
    Configure::write('Ai.providers.openai-compatible.key');

    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Embeddings::for(['test'])->generate(provider: 'openai-compatible');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => !$request->hasHeader('Authorization'));
});

test('embeddings request includes provider options in the request body', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Embeddings::for(['Hello'])->withProviderOptions(['user' => 'test-user'])->generate(provider: 'openai-compatible');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['user'] === 'test-user'
            && $body['input'] === ['Hello'];
    });
});

test('embeddings reject response formats that do not return float vectors', function (): void {
    expect(fn(): mixed => Embeddings::for(['Hello'])->withProviderOptions(['encoding_format' => 'base64'])->generate(provider: 'openai-compatible'))
        ->toThrow(InvalidArgumentException::class, 'only supports float embedding responses');
});

test('throws when no default embeddings model is configured and none is passed', function (): void {
    Configure::write('Ai.providers.openai-compatible', [
        'className' => OpenAiCompatibleProvider::class,
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
    ]);

    expect(fn(): mixed => Embeddings::for(['Hello'])->generate(provider: 'openai-compatible'))
        ->toThrow(InvalidArgumentException::class, 'requires a default embeddings model');
});

test('embeddings request omits dimensions when none are configured', function (): void {
    Configure::write('Ai.providers.openai-compatible', [
        'className' => OpenAiCompatibleProvider::class,
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
        'models' => ['embeddings' => ['default' => 'local-embeddings-model']],
    ]);

    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Embeddings::for(['Hello'])->generate(provider: 'openai-compatible');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'local-embeddings-model'
            && !array_key_exists('dimensions', $body);
    });
});

test('embeddings request omits dimensions when an explicit model is passed', function (): void {
    Configure::write('Ai.providers.openai-compatible', [
        'className' => OpenAiCompatibleProvider::class,
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
    ]);

    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Embeddings::for(['Hello'])->generate(provider: 'openai-compatible', model: 'explicit-embeddings-model');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'explicit-embeddings-model'
            && !array_key_exists('dimensions', $body);
    });
});

test('automatic embedding fakes require dimensions when the model dimensions are unknown', function (): void {
    Configure::write('Ai.providers.openai-compatible', [
        'className' => OpenAiCompatibleProvider::class,
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'models' => ['embeddings' => ['default' => 'local-embeddings-model']],
    ]);

    Embeddings::fake();

    Embeddings::for(['Hello'])->generate(provider: 'openai-compatible');
})->throws(RuntimeException::class, 'Unable to generate fake embeddings without positive dimensions');

test('embeddings reject responses with missing vectors', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'usage' => ['prompt_tokens' => 1],
    ])]);

    expect(fn(): mixed => Embeddings::for(['Hello'])->generate(provider: 'openai-compatible'))
        ->toThrow(AiException::class, 'expected number of embeddings');
});

test('embeddings reject malformed vectors', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => ['invalid']]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    expect(fn(): mixed => Embeddings::for(['Hello'])->generate(provider: 'openai-compatible'))
        ->toThrow(AiException::class, 'invalid embedding vector');
});

test('embeddings rate limit response throws rate limited exception', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['error' => ['message' => 'Rate limited']], 429)]);

    expect(fn(): mixed => Embeddings::for(['Hello'])->generate(provider: 'openai-compatible'))
        ->toThrow(RateLimitedException::class);
});

test('embeddings overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['error' => ['message' => 'Server overloaded']], 503)]);

    expect(fn(): mixed => Embeddings::for(['Hello'])->generate(provider: 'openai-compatible'))
        ->toThrow(ProviderOverloadedException::class);
});

test('embeddings error in 200 response throws ai exception', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'The model does not exist.',
        ],
    ])]);

    expect(fn(): mixed => Embeddings::for(['Hello'])->generate(provider: 'openai-compatible'))
        ->toThrow(AiException::class, 'OpenAI-compatible Error');
});
