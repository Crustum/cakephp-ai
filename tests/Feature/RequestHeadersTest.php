<?php
declare(strict_types=1);

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Crustum\Ai\Audio;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Files;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Image;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Reranking;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Transcription;

beforeEach(function (): void {
    if (!Cache::getConfig('array')) {
        Cache::setConfig('array', [
            'className' => 'Array',
        ]);
    }

    Configure::write('Ai.providers.cohere.key', 'test-key');
    Configure::write('Ai.providers.openai.key', 'test-key');
    Configure::write('Ai.caching.embeddings.store', 'array');
});

afterEach(function (): void {
    Cache::clear('array');
});

function fakeEmbeddingsHeadersResponse(): array
{
    return [
        '*' => aiHttpResponse([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ]),
    ];
}

test('extra headers are sent with embeddings requests and never in the body', function (): void {
    aiHttpFake(fakeEmbeddingsHeadersResponse());

    Embeddings::for(['Hello'])
        ->withHeaders(['X-Tenant' => 'acme'])
        ->withProviderOptions(['user' => 'acme'])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && !array_key_exists('X-Tenant', $body)
            && $body['user'] === 'acme';
    });
});

test('extra headers are sent with transcription requests', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hello'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withHeaders(['X-Tenant' => 'acme'])
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('X-Tenant', 'acme')
        && multipartField($request, 'X-Tenant') === null);
});

test('extra headers resolved from a closure survive serialization', function (): void {
    aiHttpFake(fakeEmbeddingsHeadersResponse());

    $pending = Embeddings::for(['Hello'])->withHeaders(
        fn(Provider $provider): array => ['X-Tenant' => $provider->driver() . '-acme'],
    );

    unserialize(serialize($pending))->generate(provider: 'openai', model: 'text-embedding-3-small');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('X-Tenant', 'openai-acme'));
});

test('extra headers do not participate in the embeddings cache key', function (): void {
    aiHttpFake(fakeEmbeddingsHeadersResponse());

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withHeaders(['X-Tenant' => 'acme'])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    Embeddings::for(['Hello'])
        ->cache(3600)
        ->withHeaders(['X-Tenant' => 'globex'])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    aiAssertHttpSentCount(1);
});

test('extra headers replace configured headers regardless of casing', function (): void {
    Configure::write('Ai.providers.openai', [
        ...(array)Configure::read('Ai.providers.openai'),
        'headers' => ['X-Tenant' => 'from-config'],
    ]);

    aiHttpFake(fakeEmbeddingsHeadersResponse());

    Embeddings::for(['Hello'])
        ->withHeaders(['x-tenant' => 'from-request'])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->header('X-Tenant') === ['from-request']);
});

test('extra headers are sent with image requests', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['data' => [['b64_json' => base64_encode('fake-image')]]])]);

    Image::of('A red apple')
        ->withHeaders(['X-Tenant' => 'acme'])
        ->generate(provider: 'openai', model: 'dall-e-3');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && !array_key_exists('X-Tenant', $body);
    });
});

test('extra headers are sent with audio requests', function (): void {
    aiHttpFake(['*' => aiHttpResponse('fake-audio-bytes')]);

    Audio::of('Hello world')
        ->withHeaders(['X-Tenant' => 'acme'])
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && !array_key_exists('X-Tenant', $body);
    });
});

test('extra headers are sent with reranking requests', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['results' => [['index' => 0, 'relevance_score' => 0.95]]])]);

    Reranking::of(['CakePHP is a PHP framework'])
        ->withHeaders(['X-Tenant' => 'acme'])
        ->rerank('What is CakePHP?', provider: 'cohere', model: 'rerank-v3.5');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('X-Tenant', 'acme')
            && !array_key_exists('X-Tenant', $body);
    });
});

test('extra headers are sent with file uploads', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['id' => 'file-abc123'])]);

    Files::put(
        Document::fromString('Hello, World!', 'text/plain')
            ->as('hello.txt')
            ->withHeaders(['X-Tenant' => 'acme']),
        provider: 'openai',
    );

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('X-Tenant', 'acme')
        && multipartField($request, 'X-Tenant') === null);
});

test('file upload provider options are resolved once', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['id' => 'file-abc123'])]);

    $resolutions = 0;

    Files::put(
        Document::fromString('Hello, World!', 'text/plain')
            ->withHeaders(function () use (&$resolutions): array {
                $resolutions++;

                return ['X-Tenant' => 'acme'];
            }),
        provider: 'openai',
    );

    expect($resolutions)->toBe(1);
});
