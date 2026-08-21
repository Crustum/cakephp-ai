<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Reranking;

beforeEach(function (): void {
    Configure::write('Ai.providers.cohere', [

        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
    ]);
});

test('embeddings rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse(['message' => 'Rate limit exceeded'], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse(['message' => 'Service overloaded'], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse(['message' => 'Unauthorized'], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
})->throws(RequestException::class);

test('reranking rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse(['message' => 'Rate limit exceeded'], 429),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');
})->throws(RateLimitedException::class);

test('reranking overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse(['message' => 'Service overloaded'], 503),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');
})->throws(ProviderOverloadedException::class);

test('reranking http error response throws request exception', function (): void {
    aiHttpFake([
        'api.cohere.com/*' => aiHttpResponse(['message' => 'Unauthorized'], 401),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');
})->throws(RequestException::class);
