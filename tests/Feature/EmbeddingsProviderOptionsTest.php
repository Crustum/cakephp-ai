<?php
declare(strict_types=1);

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Job\GenerateEmbeddingsJob;
use Crustum\Ai\Prompts\QueuedEmbeddingsPrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    if (!Cache::getConfig('array')) {
        Cache::setConfig('array', [
            'className' => 'Array',
        ]);
    }

    Configure::write('Ai.providers.cohere', [
        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
    ]);
    Configure::write('Ai.providers.openai', [
        ...(array)Configure::read('Ai.providers.openai'),
        'key' => 'test-key',
    ]);
    Configure::write('Ai.caching.embeddings.store', 'array');
});

afterEach(function (): void {
    Cache::clear('array');
});

test('cache key differs by provider options so distinct option sets do not collide', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['input_type' => 'search_document'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['input_type' => 'search_query'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    $observed = collect(aiHttpRecorded())
        ->map(fn(array $pair): mixed => json_decode((string)$pair[0]->body(), true)['input_type'] ?? null)
        ->toList();

    expect($observed)->toBe(['search_document', 'search_query']);
});

test('cache key is stable for the same provider options', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['input_type' => 'search_query'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['input_type' => 'search_query'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(aiHttpRecorded())->toHaveCount(1);
});

test('cache key is insensitive to provider option key order', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['input_type' => 'search_query', 'truncate' => 'END'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withProviderOptions(['truncate' => 'END', 'input_type' => 'search_query'])
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    expect(aiHttpRecorded())->toHaveCount(1);
});

test('closure resolver receives the resolved provider and applies per-provider options', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse([
            'embeddings' => ['float' => [[0.1]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
        'api.openai.com/*' => aiHttpResponse([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ]),
    ]);

    $seen = [];

    Embeddings::for(['Hello'])
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return $provider->driver() === 'cohere'
                ? ['input_type' => 'search_query']
                : ['encoding_format' => 'base64'];
        })
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    expect($seen)->toBe(['cohere']);

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ($body['input_type'] ?? null) === 'search_query';
    });
});

test('closure provider options are not recorded on the queued prompt fake', function (): void {
    Embeddings::fake();

    Embeddings::for(['Hello'])
        ->withProviderOptions(fn(Provider $provider): array => ['input_type' => 'search_query'])
        ->queue(provider: 'cohere', model: 'embed-v4.0');

    Embeddings::assertQueued(
        fn(QueuedEmbeddingsPrompt $prompt): bool => $prompt->providerOptions === [],
    );
});

test('closure provider options survive queue serialization round-trip', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse([
            'embeddings' => ['float' => [[0.1]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);

    $pending = Embeddings::for(['Hello'])
        ->withProviderOptions(fn(Provider $provider): array => ['input_type' => 'search_query']);

    $payload = GenerateEmbeddingsJob::payload($pending, 'cohere', 'embed-v4.0');

    $restored = unserialize(serialize($payload));

    (new GenerateEmbeddingsJob())->run($restored);

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ($body['input_type'] ?? null) === 'search_query';
    });
});

test('closure resolver returning null is treated as no options', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse([
            'embeddings' => ['float' => [[0.1]]],
            'meta' => ['billed_units' => ['input_tokens' => 1]],
        ]),
    ]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(fn(): null => null)
        ->generate(provider: 'cohere', model: 'embed-v4.0');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return ($body['input_type'] ?? null) === 'search_document';
    });
});
