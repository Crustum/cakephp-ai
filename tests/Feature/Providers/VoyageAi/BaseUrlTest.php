<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Reranking;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

function fakeVoyageBaseUrlEmbeddingsResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        'model' => 'voyage-4',
        'usage' => ['total_tokens' => 5],
    ]);
}

function fakeVoyageBaseUrlRerankingResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'object' => 'list',
        'data' => [['index' => 0, 'relevance_score' => 0.9]],
        'model' => 'rerank-2.5-lite',
        'usage' => ['total_tokens' => 5],
    ]);
}

test('voyageai embedding requests use the configured base url', function (): void {
    Configure::write('Ai.providers.voyageai', [

        ...(array)Configure::read('Ai.providers.voyageai'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]);

    aiHttpFake(['*' => fakeVoyageBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => $r->url() === 'http://localhost:8080/v1/embeddings');
});

test('voyageai reranking requests use the configured base url', function (): void {
    Configure::write('Ai.providers.voyageai', [

        ...(array)Configure::read('Ai.providers.voyageai'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]);

    aiHttpFake(['*' => fakeVoyageBaseUrlRerankingResponse()]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'voyageai', model: 'rerank-2.5-lite');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => $r->url() === 'http://localhost:8080/v1/rerank');
});

test('voyageai requests fall back to the default base url', function (): void {
    Configure::write('Ai.providers.voyageai', array_diff_key(
        [...(array)Configure::read('Ai.providers.voyageai'), 'key' => 'test-key'],
        ['url' => null],
    ));

    aiHttpFake(['*' => fakeVoyageBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => $r->url() === 'https://api.voyageai.com/v1/embeddings');
});
