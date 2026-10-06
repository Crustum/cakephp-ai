<?php
declare(strict_types=1);

use Aws\MockHandler;
use Crustum\Ai\Ai;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Reranking;
use Crustum\Ai\Responses\Data\RankedDocument;
use Crustum\Ai\Test\Feature\Providers\Bedrock\BedrockHelpersTrait;
use Crustum\Ai\Test\Support\IntegrationPrompts;

uses(BedrockHelpersTrait::class);

test('reranking request includes model, query, and documents', function (): void {
    $mock = $this->bedrockInvokeMock($this->fakeBedrockRerankingResponse());

    Ai::manager()->rerankingProvider('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
    );

    Reranking::of(['CakePHP is a PHP framework', 'React is a JS library'])
        ->rerank(IntegrationPrompts::question('knowledge'), provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

    $command = $mock->getLastCommand();

    expect($command['modelId'])->toBe('cohere.rerank-v3-5:0')
        ->and(json_decode($command['body'], true))->toBe([
            'query' => IntegrationPrompts::question('knowledge'),
            'documents' => ['CakePHP is a PHP framework', 'React is a JS library'],
            'api_version' => 2,
        ]);
});

test('reranking request omits api version for amazon rerank models', function (): void {
    $mock = $this->bedrockInvokeMock($this->fakeBedrockRerankingResponse());

    Ai::manager()->rerankingProvider('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
    );

    Reranking::of(['CakePHP is a PHP framework', 'React is a JS library'])
        ->rerank(IntegrationPrompts::question('knowledge'), provider: 'bedrock', model: 'amazon.rerank-v1:0');

    expect(json_decode($mock->getLastCommand()['body'], true))->toBe([
        'query' => IntegrationPrompts::question('knowledge'),
        'documents' => ['CakePHP is a PHP framework', 'React is a JS library'],
    ]);
});

test('reranking request includes top_n when limit set', function (): void {
    $mock = $this->bedrockInvokeMock($this->fakeBedrockRerankingResponse());

    Ai::manager()->rerankingProvider('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
    );

    Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->limit(2)
        ->rerank('query', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

    expect(json_decode($mock->getLastCommand()['body'], true)['top_n'])->toBe(2);
});

test('reranking response is correctly parsed into RankedDocuments', function (): void {
    Ai::manager()->rerankingProvider('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->fakeBedrockInvoke($this->fakeBedrockRerankingResponse())),
    );

    $response = Reranking::of(['CakePHP is a PHP framework', 'React is a JS library'])
        ->rerank(IntegrationPrompts::question('knowledge'), provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

    expect($response)->toHaveCount(2)
        ->and($response->first())->toBeInstanceOf(RankedDocument::class)
        ->and($response->first()->index)->toBe(0)
        ->and($response->first()->document)->toBe('CakePHP is a PHP framework')
        ->and($response->first()->score)->toBe(0.95)
        ->and($response->meta->provider)->toBe('bedrock')
        ->and($response->meta->model)->toBe('cohere.rerank-v3-5:0');
});

test('reranking uses default model when none specified', function (): void {
    $mock = $this->bedrockInvokeMock($this->fakeBedrockRerankingResponse());

    Ai::manager()->rerankingProvider('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->bedrockClient($mock)),
    );

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'bedrock');

    expect($mock->getLastCommand()['modelId'])->toBe('cohere.rerank-v3-5:0');
});

test('reranking maps documents by index when results are returned out of order', function (): void {
    Ai::manager()->rerankingProvider('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->fakeBedrockInvoke([
            'results' => [
                ['index' => 2, 'relevance_score' => 0.91],
                ['index' => 0, 'relevance_score' => 0.42],
                ['index' => 1, 'relevance_score' => 0.10],
            ],
        ])),
    );

    $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->rerank('query', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');

    $ranked = $response->collect()->toList();

    expect($ranked[0]->index)->toBe(2)
        ->and($ranked[0]->document)->toBe('Doc C')
        ->and($ranked[0]->score)->toBe(0.91)
        ->and($ranked[1]->index)->toBe(0)
        ->and($ranked[1]->document)->toBe('Doc A');
});

test('reranking throttling maps to rate limited exception', function (): void {
    Ai::manager()->rerankingProvider('bedrock')->useRerankingGateway(
        $this->rerankingGatewayWithClient($this->bedrockClient(new MockHandler([
            $this->mockBedrockException('ThrottlingException', 429),
        ]))),
    );

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'bedrock', model: 'cohere.rerank-v3-5:0');
})->throws(RateLimitedException::class);
