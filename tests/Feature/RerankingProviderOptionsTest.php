<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Reranking;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.cohere.key', 'test-key');
    Configure::write('Ai.providers.voyageai.key', 'test-key');
});

function fakeRerankingOptionsResponse(): array
{
    return [
        '*' => aiHttpResponse([
            'results' => [['index' => 0, 'relevance_score' => 0.9]],
        ]),
    ];
}

test('flat provider options are sent on the reranking request', function (): void {
    aiHttpFake(fakeRerankingOptionsResponse());

    Reranking::of(['CakePHP is a PHP framework'])
        ->withProviderOptions(['max_tokens_per_doc' => 512])
        ->rerank('What is CakePHP?', provider: 'cohere', model: 'rerank-v3.5');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['max_tokens_per_doc'] === 512);
});

test('provider options may not override the core reranking request payload', function (): void {
    aiHttpFake(fakeRerankingOptionsResponse());

    Reranking::of(['CakePHP is a PHP framework'])
        ->withProviderOptions(['model' => 'hijacked', 'query' => 'hijacked'])
        ->rerank('What is CakePHP?', provider: 'cohere', model: 'rerank-v3.5');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'rerank-v3.5' && $body['query'] === 'What is CakePHP?';
    });
});

test('closure provider options receive the resolved reranking provider', function (): void {
    aiHttpFake(fakeRerankingOptionsResponse());

    $seen = [];

    Reranking::of(['CakePHP is a PHP framework'])
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return ['max_tokens_per_doc' => 512];
        })
        ->rerank('What is CakePHP?', provider: 'cohere', model: 'rerank-v3.5');

    expect($seen)->toBe(['cohere']);

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['max_tokens_per_doc'] === 512);
});

test('falsy provider options are not dropped from the reranking request', function (): void {
    aiHttpFake(fakeRerankingOptionsResponse());

    Reranking::of(['CakePHP is a PHP framework'])
        ->withProviderOptions(['return_documents' => false])
        ->rerank('What is CakePHP?', provider: 'cohere', model: 'rerank-v3.5');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return array_key_exists('return_documents', $body) && $body['return_documents'] === false;
    });
});

test('the limit still wins over a voyage top_k provider option', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse([
            'data' => [['index' => 0, 'relevance_score' => 0.9]],
        ]),
    ]);

    Reranking::of(['CakePHP is a PHP framework'])
        ->limit(1)
        ->withProviderOptions(['top_k' => 99, 'truncation' => false])
        ->rerank('What is CakePHP?', provider: 'voyageai', model: 'rerank-2.5-lite');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['top_k'] === 1 && $body['truncation'] === false;
    });
});
