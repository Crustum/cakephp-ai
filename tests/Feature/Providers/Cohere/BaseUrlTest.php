<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Reranking;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

function fakeCohereBaseUrlEmbeddingsResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
        'meta' => ['billed_units' => ['input_tokens' => 5]],
    ]);
}

function fakeCohereBaseUrlRerankingResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'results' => [['index' => 0, 'relevance_score' => 0.9]],
        'meta' => ['billed_units' => ['search_units' => 1]],
    ]);
}

test('cohere embedding requests use the configured base url', function (): void {
    Configure::write('Ai.providers.cohere', [

        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v2',
    ]);

    aiHttpFake(['*' => fakeCohereBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => $r->url() === 'http://localhost:8080/v2/embed');
});

test('cohere reranking requests use the configured base url', function (): void {
    Configure::write('Ai.providers.cohere', [

        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v2',
    ]);

    aiHttpFake(['*' => fakeCohereBaseUrlRerankingResponse()]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => $r->url() === 'http://localhost:8080/v2/rerank');
});

test('cohere requests fall back to the default base url', function (): void {
    Configure::write('Ai.providers.cohere', [

        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
    ]);

    aiHttpFake(['*' => fakeCohereBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => $r->url() === 'https://api.cohere.com/v2/embed');
});
