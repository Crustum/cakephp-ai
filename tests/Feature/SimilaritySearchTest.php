<?php
declare(strict_types=1);

use Crustum\Ai\Test\Fixtures\SimilaritySearch\FakeVectorTable;
use Crustum\Ai\Tools\Request;
use Crustum\Ai\Tools\SimilaritySearch;

test('search results are returned', function (): void {
    $data = [
        [
            'id' => 1,
            'query' => 'Test query',
        ],
        [
            'id' => 2,
            'query' => 'Test query',
        ],
    ];

    $search = new SimilaritySearch(using: fn(string $query): array => $data);

    $results = $search->handle(new Request([
        'query' => 'Test query',
    ]));

    expect(str_contains($results, json_encode($data, JSON_PRETTY_PRINT)))->toBeTrue();
});

test('using model rejects blank model class', function (): void {
    SimilaritySearch::usingModel('', 'embedding');
})->throws(InvalidArgumentException::class, 'A model class name is required for similarity search.');

test('using model rejects blank column name', function (): void {
    SimilaritySearch::usingModel(FakeVectorTable::class, '  ');
})->throws(InvalidArgumentException::class, 'A vector column name is required for similarity search.');

test('using model creates similarity search', function (): void {
    $search = SimilaritySearch::usingModel(
        FakeVectorTable::class,
        'embedding',
        0.7,
    );

    $results = $search->handle(new Request([
        'query' => 'search term',
    ]));

    expect($results)->toContain('Relevant results found.')
        ->toContain('First document')
        ->toContain('Second document');
});

test('using model applies custom query closure', function (): void {
    $search = SimilaritySearch::usingModel(
        FakeVectorTable::class,
        'embedding',
        query: fn($builder) => $builder->where(['status' => 'published']),
    );

    $results = $search->handle(new Request([
        'query' => 'search term',
    ]));

    expect($results)->toContain('Relevant results found.');
});

test('using model excludes embedding column from results', function (): void {
    $search = SimilaritySearch::usingModel(
        FakeVectorTable::class,
        'embedding',
    );

    $results = $search->handle(new Request([
        'query' => 'search term',
    ]));

    expect($results)->not->toContain('embedding');
});
