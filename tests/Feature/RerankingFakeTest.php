<?php
declare(strict_types=1);

use Cake\Collection\Collection;
use Cake\Core\Configure;
use Crustum\Ai\Collections;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Prompts\RerankingPrompt;
use Crustum\Ai\Reranking;
use Crustum\Ai\Responses\Data\RankedDocument;
use Crustum\Ai\Test\Support\IntegrationPrompts;

beforeEach(function (): void {
    Configure::write('Ai.default_for_reranking', 'cohere');
});

test('rerank rejects empty document list', function (): void {
    Reranking::fake();

    Reranking::of([])->rerank(IntegrationPrompts::question('knowledge'));
})->throws(InvalidArgumentException::class, 'At least one document is required to rerank.');

test('rerank rejects empty collection of documents', function (): void {
    Reranking::fake();

    Reranking::of(collection([]))->rerank(IntegrationPrompts::question('knowledge'));
})->throws(InvalidArgumentException::class, 'At least one document is required to rerank.');

test('rerank rejects blank document strings', function (): void {
    Reranking::fake();

    Reranking::of([''])->rerank(IntegrationPrompts::question('knowledge'));
})->throws(InvalidArgumentException::class, 'Each document to rerank must be a non-blank string (index 0).');

test('rerank rejects whitespace-only document strings', function (): void {
    Reranking::fake();

    Reranking::of([" \t\n"])->rerank(IntegrationPrompts::question('knowledge'));
})->throws(InvalidArgumentException::class, 'Each document to rerank must be a non-blank string (index 0).');

test('rerank rejects non-string documents', function (): void {
    Reranking::fake();

    Reranking::of([123])->rerank(IntegrationPrompts::question('knowledge'));
})->throws(InvalidArgumentException::class, 'Each document to rerank must be a non-blank string (index 0).');

test('can fake reranking', function (): void {
    Reranking::fake();

    $response = Reranking::of([
        'CakePHP is a PHP framework',
        'Python is a programming language',
        'React is a JavaScript library',
    ])->rerank(IntegrationPrompts::question('knowledge'));

    expect($response)->toHaveCount(3)
        ->and($response->first())->toBeInstanceOf(RankedDocument::class);
});

test('can fake reranking with limit', function (): void {
    Reranking::fake();

    $response = Reranking::of([
        'CakePHP is a PHP framework',
        'Python is a programming language',
        'React is a JavaScript library',
        'Vue is a JavaScript framework',
        'Ruby is a programming language',
    ])->limit(3)->rerank(IntegrationPrompts::question('knowledge'));

    expect($response)->toHaveCount(3);
});

test('can fake reranking with custom response', function (): void {
    Reranking::fake([
        [
            new RankedDocument(index: 0, document: 'First doc', score: 0.95),
            new RankedDocument(index: 1, document: 'Second doc', score: 0.75),
        ],
    ]);

    $response = Reranking::of(['First doc', 'Second doc'])->rerank('query');

    expect($response)->toHaveCount(2)
        ->and($response->first()->score)->toEqual(0.95)
        ->and($response->first()->document)->toEqual('First doc');
});

test('can fake reranking with closure', function (): void {
    Reranking::fake(fn(RerankingPrompt $prompt): array => (new Collection($prompt->documents))->map(fn($doc, $index): RankedDocument => new RankedDocument(
        index: $index,
        document: $doc,
        score: 1.0 - ($index * 0.1),
    ))->toList());

    $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])->rerank('test query');

    expect($response)->toHaveCount(3)
        ->and($response->first()->score)->toEqual(1.0)
        ->and($response->first()->document)->toEqual('Doc A');
});

test('can assert reranked', function (): void {
    Reranking::fake();

    Reranking::of(['CakePHP is great', 'PHP is cool'])->rerank('CakePHP');

    Reranking::assertReranked(fn(RerankingPrompt $prompt): bool => $prompt->contains('CakePHP'));

    Reranking::assertReranked(fn(RerankingPrompt $prompt): bool => $prompt->documentsContain('CakePHP is great'));
});

test('can assert not reranked', function (): void {
    Reranking::fake();

    Reranking::of(['CakePHP is great'])->rerank('CakePHP');

    Reranking::assertNotReranked(fn(RerankingPrompt $prompt): bool => $prompt->contains('Python'));

    Reranking::assertNotReranked(fn(RerankingPrompt $prompt): bool => $prompt->documentsContain('Python is great'));
});

test('can assert nothing reranked', function (): void {
    Reranking::fake();

    Reranking::assertNothingReranked();
});

test('can prevent stray rerankings', function (): void {
    Reranking::fake()->preventStrayRerankings();

    Reranking::of(['Doc 1', 'Doc 2'])->rerank('query');
})->throws(RuntimeException::class);

test('fake reranking shuffles documents', function (): void {
    Reranking::fake();

    $documents = ['Doc A', 'Doc B', 'Doc C', 'Doc D', 'Doc E'];

    $response = Reranking::of($documents)->rerank('query');

    expect($response)->toHaveCount(5);

    foreach ($response as $result) {
        expect($documents)->toContain($result->document)
            ->and($result->document)->toEqual($documents[$result->index]);
    }
});

test('can iterate over response', function (): void {
    Reranking::fake();

    $response = Reranking::of(['Doc A', 'Doc B'])->rerank('query');

    $documents = [];

    foreach ($response as $result) {
        $documents[] = $result->document;
    }

    expect($documents)->toHaveCount(2);
});

test('can get documents in reranked order', function (): void {
    Reranking::fake([
        [
            new RankedDocument(index: 1, document: 'Second', score: 0.9),
            new RankedDocument(index: 0, document: 'First', score: 0.5),
        ],
    ]);

    $response = Reranking::of(['First', 'Second'])->rerank('query');

    expect($response->documents()->toList())->toEqual(['Second', 'First']);
});

test('rerank accepts ai provider enum', function (): void {
    Reranking::fake();

    Reranking::of(['CakePHP is great', 'PHP is cool'])->rerank('CakePHP', provider: Lab::Cohere);

    Reranking::assertReranked(fn(RerankingPrompt $prompt): bool => $prompt->contains('CakePHP'));
});

test('prompt records limit', function (): void {
    Reranking::fake();

    Reranking::of(['Doc A', 'Doc B', 'Doc C'])->limit(2)->rerank('query');

    Reranking::assertReranked(fn(RerankingPrompt $prompt): bool => $prompt->limit === 2 && $prompt->count() === 3);
});

test('prompt records timeout', function (): void {
    Reranking::fake();

    Reranking::of(['Doc A'])->timeout(45)->rerank('query');

    Reranking::assertReranked(fn(RerankingPrompt $prompt): bool => $prompt->timeout === 45);
});

test('collection rerank records timeout', function (): void {
    Reranking::fake();

    Collections::of([['body' => 'Doc A']])->rerank(query: 'query', by: 'body', timeout: 45);

    Reranking::assertReranked(fn(RerankingPrompt $prompt): bool => $prompt->timeout === 45);
});

test('collection rerank returns items in reranked order', function (): void {
    Reranking::fake([
        [
            new RankedDocument(index: 1, document: 'Second', score: 0.9),
            new RankedDocument(index: 0, document: 'First', score: 0.5),
        ],
    ]);

    $reranked = Collections::of(['First', 'Second'])->rerank(query: 'query');

    expect($reranked->toList())->toEqual(['Second', 'First']);
});
