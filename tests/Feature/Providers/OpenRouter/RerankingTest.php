<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Reranking;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Test\Support\IntegrationPrompts;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter', [

        ...(array)Configure::read('Ai.providers.openrouter'),
        'key' => 'test-key',
    ]);
});

test('reranking request includes model, query, and documents', function (): void {
    aiHttpFake(['*' => fakeOpenRouterRerankingResponse()]);

    Reranking::of(['CakePHP is a PHP framework', 'React is a JS library'])
        ->rerank(IntegrationPrompts::question('knowledge'), provider: 'openrouter', model: 'cohere/rerank-v3.5');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'cohere/rerank-v3.5'
            && $body['query'] === IntegrationPrompts::question('knowledge')
            && $body['documents'] === ['CakePHP is a PHP framework', 'React is a JS library']
            && ! array_key_exists('top_n', $body)
            && $request->url() === 'https://openrouter.ai/api/v1/rerank';
    });
});

test('reranking request includes top_n when limit set', function (): void {
    aiHttpFake(['*' => fakeOpenRouterRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->limit(2)
        ->rerank('query', provider: 'openrouter', model: 'cohere/rerank-v3.5');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['top_n'] === 2);
});

test('reranking uses default model when none specified', function (): void {
    aiHttpFake(['*' => fakeOpenRouterRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model'] === 'cohere/rerank-4-pro');
});

test('reranking maps documents by index when results are returned out of order', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'results' => [
            ['index' => 2, 'relevance_score' => 0.91],
            ['index' => 0, 'relevance_score' => 0.42],
            ['index' => 1, 'relevance_score' => 0.10],
        ],
    ])]);

    $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->rerank('query', provider: 'openrouter', model: 'cohere/rerank-v3.5');

    $ranked = $response->results;

    expect($ranked[0]->index)->toBe(2)
        ->and($ranked[0]->document)->toBe('Doc C')
        ->and($ranked[0]->score)->toBe(0.91)
        ->and($ranked[1]->index)->toBe(0)
        ->and($ranked[1]->document)->toBe('Doc A')
        ->and($response->meta->provider)->toBe('openrouter')
        ->and($response->meta->model)->toBe('cohere/rerank-v3.5');
});

test('reranking error in 200 response throws ai exception', function (): void {
    aiHttpFake(['openrouter.ai/*' => aiHttpResponse([
        'error' => ['type' => 'invalid_request_error', 'message' => 'The model does not exist.'],
    ])]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'openrouter', model: 'cohere/rerank-v3.5');
})->throws(AiException::class, 'OpenRouter Error');

test('reranking rate limit response throws rate limited exception', function (): void {
    aiHttpFake(['openrouter.ai/*' => aiHttpResponse(['message' => 'rate limit exceeded'], 429)]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'openrouter', model: 'cohere/rerank-v3.5');
})->throws(RateLimitedException::class);

function fakeOpenRouterRerankingResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'results' => [
            ['index' => 0, 'relevance_score' => 0.95],
            ['index' => 1, 'relevance_score' => 0.12],
        ],
    ]);
}

test('reranking response reports the total tokens and search units', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'results' => [['index' => 0, 'relevance_score' => 0.95]],
        'usage' => ['total_tokens' => 320, 'search_units' => 1],
    ])]);

    $response = Reranking::of(['Doc A'])->rerank('query', provider: 'openrouter', model: 'cohere/rerank-v3.5');

    expect($response->usage->inputTokens)->toBe(320)
        ->and($response->usage->searchUnits)->toBe(1.0);
});
