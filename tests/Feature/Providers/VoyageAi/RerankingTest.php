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
    Configure::write('Ai.providers.voyageai', [

        ...(array)Configure::read('Ai.providers.voyageai'),
        'key' => 'test-key',
    ]);
});

test('reranking request includes model, query, and documents', function (): void {
    aiHttpFake(['*' => fakeVoyageRerankingResponse()]);

    Reranking::of(['CakePHP is a PHP framework', 'React is a JS library'])
        ->rerank(IntegrationPrompts::question('knowledge'), provider: 'voyageai', model: 'rerank-2.5-lite');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'rerank-2.5-lite'
            && $body['query'] === IntegrationPrompts::question('knowledge')
            && $body['documents'] === ['CakePHP is a PHP framework', 'React is a JS library']
            && ! array_key_exists('top_k', $body)
            && $request->url() === 'https://api.voyageai.com/v1/rerank';
    });
});

test('reranking request includes top_k when limit set', function (): void {
    aiHttpFake(['*' => fakeVoyageRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->limit(2)
        ->rerank('query', provider: 'voyageai', model: 'rerank-2.5-lite');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['top_k'] === 2);
});

test('reranking response is correctly parsed into RankedDocuments', function (): void {
    aiHttpFake(['*' => fakeVoyageRerankingResponse()]);

    $response = Reranking::of(['CakePHP is a PHP framework', 'React is a JS library'])
        ->rerank(IntegrationPrompts::question('knowledge'), provider: 'voyageai', model: 'rerank-2.5-lite');

    expect($response)->toHaveCount(2)
        ->and($response->first())->toBeInstanceOf(RankedDocument::class)
        ->and($response->first()->index)->toBe(0)
        ->and($response->first()->document)->toBe('CakePHP is a PHP framework')
        ->and($response->first()->score)->toBe(0.95)
        ->and($response->meta->provider)->toBe('voyageai')
        ->and($response->meta->model)->toBe('rerank-2.5-lite');
});

test('reranking request sends bearer token', function (): void {
    aiHttpFake(['*' => fakeVoyageRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'voyageai', model: 'rerank-2.5-lite');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('reranking rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'api.voyageai.com/*' => aiHttpResponse(['detail' => 'Rate limit exceeded'], 429),
    ]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'voyageai', model: 'rerank-2.5-lite');
})->throws(RateLimitedException::class);

test('reranking overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'api.voyageai.com/*' => aiHttpResponse(['detail' => 'Service unavailable'], 503),
    ]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'voyageai', model: 'rerank-2.5-lite');
})->throws(ProviderOverloadedException::class);

test('reranking http error response throws request exception', function (): void {
    aiHttpFake([
        'api.voyageai.com/*' => aiHttpResponse(['detail' => 'Invalid model'], 400),
    ]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'voyageai', model: 'rerank-2.5-lite');
})->throws(RequestException::class);

function fakeVoyageRerankingResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'object' => 'list',
        'data' => [
            ['index' => 0, 'relevance_score' => 0.95],
            ['index' => 1, 'relevance_score' => 0.12],
        ],
        'model' => 'rerank-2.5-lite',
        'usage' => ['total_tokens' => 25],
    ]);
}
