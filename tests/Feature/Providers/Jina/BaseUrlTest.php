<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Reranking;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

function fakeJinaBaseUrlEmbeddingsResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'model' => 'jina-embeddings-v4',
        'data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        'usage' => ['total_tokens' => 5],
    ]);
}

function fakeJinaBaseUrlRerankingResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'model' => 'jina-reranker-v3',
        'results' => [['index' => 0, 'relevance_score' => 0.9]],
    ]);
}

test('jina embedding requests use the configured base url', function (): void {
    Configure::write('Ai.providers.jina', [

        ...(array)Configure::read('Ai.providers.jina'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]);

    aiHttpFake(['*' => fakeJinaBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => $r->url() === 'http://localhost:8080/v1/embeddings');
});

test('jina reranking requests use the configured base url', function (): void {
    Configure::write('Ai.providers.jina', [

        ...(array)Configure::read('Ai.providers.jina'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]);

    aiHttpFake(['*' => fakeJinaBaseUrlRerankingResponse()]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => $r->url() === 'http://localhost:8080/v1/rerank');
});

test('jina requests fall back to the default base url', function (): void {
    Configure::write('Ai.providers.jina', array_diff_key(
        [...(array)Configure::read('Ai.providers.jina'), 'key' => 'test-key'],
        ['url' => null],
    ));

    aiHttpFake(['*' => fakeJinaBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => $r->url() === 'https://api.jina.ai/v1/embeddings');
});
