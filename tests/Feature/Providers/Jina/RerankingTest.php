<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Reranking;
use Crustum\Ai\Responses\Data\RankedDocument;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Test\Support\IntegrationPrompts;

beforeEach(function (): void {
    Configure::write('Ai.providers.jina', [

        ...(array)Configure::read('Ai.providers.jina'),
        'key' => 'test-key',
    ]);
});

test('reranking request includes model, query, and documents', function (): void {
    aiHttpFake(['*' => fakeJinaRerankingResponse()]);

    Reranking::of(['CakePHP is a PHP framework', 'React is a JS library'])
        ->rerank(IntegrationPrompts::question('knowledge'), provider: 'jina', model: 'jina-reranker-v3');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'jina-reranker-v3'
            && $body['query'] === IntegrationPrompts::question('knowledge')
            && $body['documents'] === ['CakePHP is a PHP framework', 'React is a JS library']
            && ! array_key_exists('top_n', $body)
            && $request->url() === 'https://api.jina.ai/v1/rerank';
    });
});

test('reranking request includes top_n when limit set', function (): void {
    aiHttpFake(['*' => fakeJinaRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->limit(2)
        ->rerank('query', provider: 'jina', model: 'jina-reranker-v3');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['top_n'] === 2);
});

test('reranking response is correctly parsed into RankedDocuments', function (): void {
    aiHttpFake(['*' => fakeJinaRerankingResponse()]);

    $response = Reranking::of(['CakePHP is a PHP framework', 'React is a JS library'])
        ->rerank(IntegrationPrompts::question('knowledge'), provider: 'jina', model: 'jina-reranker-v3');

    expect($response)->toHaveCount(2)
        ->and($response->first())->toBeInstanceOf(RankedDocument::class)
        ->and($response->first()->index)->toBe(0)
        ->and($response->first()->document)->toBe('CakePHP is a PHP framework')
        ->and($response->first()->score)->toBe(0.95)
        ->and($response->meta->provider)->toBe('jina')
        ->and($response->meta->model)->toBe('jina-reranker-v3');
});

test('reranking request sends bearer token', function (): void {
    aiHttpFake(['*' => fakeJinaRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('reranking uses default model when none specified', function (): void {
    aiHttpFake(['*' => fakeJinaRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model'] === 'jina-reranker-v3.5');
});

test('reranking maps documents by index when results are returned out of order', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'results' => [
            ['index' => 2, 'relevance_score' => 0.91],
            ['index' => 0, 'relevance_score' => 0.42],
            ['index' => 1, 'relevance_score' => 0.10],
        ],
        'model' => 'jina-reranker-v3',
        'usage' => ['total_tokens' => 25],
    ])]);

    $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->rerank('query', provider: 'jina', model: 'jina-reranker-v3');

    $ranked = $response->results;

    expect($ranked[0]->index)->toBe(2)
        ->and($ranked[0]->document)->toBe('Doc C')
        ->and($ranked[0]->score)->toBe(0.91)
        ->and($ranked[1]->index)->toBe(0)
        ->and($ranked[1]->document)->toBe('Doc A');
});

test('reranking throws when the API returns an error', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['detail' => 'unauthorized'], 401)]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');
})->throws(RequestException::class);

test('reranking rate limit response throws rate limited exception', function (): void {
    aiHttpFake(['api.jina.ai/*' => aiHttpResponse(['detail' => 'rate limit exceeded'], 429)]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');
})->throws(RateLimitedException::class);

test('reranking overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake(['api.jina.ai/*' => aiHttpResponse(['detail' => 'service unavailable'], 503)]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');
})->throws(ProviderOverloadedException::class);

function fakeJinaRerankingResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'results' => [
            ['index' => 0, 'relevance_score' => 0.95],
            ['index' => 1, 'relevance_score' => 0.12],
        ],
        'model' => 'jina-reranker-v3',
        'usage' => ['total_tokens' => 25],
    ]);
}

test('reranking response reports the total tokens', function (): void {
    aiHttpFake(['*' => fakeJinaRerankingResponse()]);

    $response = Reranking::of(IntegrationPrompts::documents('rerank'))
        ->rerank(IntegrationPrompts::question('rerank'), provider: 'jina', model: 'jina-reranker-v3');

    expect($response->usage->inputTokens)->toBe(25)
        ->and($response->usage->searchUnits)->toBeNull();
});
