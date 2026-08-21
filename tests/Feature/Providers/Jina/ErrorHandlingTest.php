<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Reranking;

beforeEach(function (): void {
    Configure::write('Ai.providers.jina', [

        ...(array)Configure::read('Ai.providers.jina'),
        'key' => 'test-key',
    ]);
});

test('embeddings rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'api.jina.ai/*' => aiHttpResponse(['detail' => 'Rate limit exceeded'], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'api.jina.ai/*' => aiHttpResponse(['detail' => 'Service overloaded'], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function (): void {
    aiHttpFake([
        'api.jina.ai/*' => aiHttpResponse(['detail' => 'Unauthorized'], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
})->throws(RequestException::class);

test('reranking rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'api.jina.ai/*' => aiHttpResponse(['detail' => 'Rate limit exceeded'], 429),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');
})->throws(RateLimitedException::class);

test('reranking overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'api.jina.ai/*' => aiHttpResponse(['detail' => 'Service overloaded'], 503),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');
})->throws(ProviderOverloadedException::class);

test('reranking http error response throws request exception', function (): void {
    aiHttpFake([
        'api.jina.ai/*' => aiHttpResponse(['detail' => 'Unauthorized'], 401),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');
})->throws(RequestException::class);
