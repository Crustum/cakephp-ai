<?php
declare(strict_types=1);

use Crustum\Ai\Event\Reranked;
use Crustum\Ai\Event\Reranking;
use Crustum\Ai\Reranking as RerankingFacade;
use Crustum\Ai\Responses\RerankingResponse;
use Crustum\Ai\Test\Support\Event\EventRecorder;
use Crustum\Ai\Test\Support\RerankCollection;
use Crustum\Ai\Test\Support\Skips\ApiKey;

test('documents can be reranked', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $recorder = EventRecorder::start([Reranking::class, Reranked::class]);

    $response = RerankingFacade::of([
        'Python is a high-level, general-purpose programming language.',
        'CakePHP is a PHP web application framework with expressive, elegant syntax.',
        'React is a JavaScript library for building user interfaces.',
    ])->rerank('What is CakePHP?', provider: $provider);

    expect($response)->toBeInstanceOf(RerankingResponse::class)
        ->toHaveCount(3)
        ->and($response->meta->provider)->toEqual($provider)
        ->and($response->first()->document)->toContain('CakePHP');

    $recorder->assertDispatched(Reranking::class);
    $recorder->assertDispatched(Reranked::class);
})->with('reranking-providers');

test('documents can be reranked with limit', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $response = RerankingFacade::of([
        'Django is a Python web framework.',
        'Rails is a Ruby web framework.',
        'CakePHP is a PHP web application framework.',
        'Express is a Node.js web framework.',
        'Spring is a Java web framework.',
    ])->limit(2)->rerank('PHP frameworks', provider: $provider);

    expect($response)->toHaveCount(2)
        ->and($response->first()->score)->toBeGreaterThan($response->results[1]->score)
        ->and($response->first()->document)->toContain('CakePHP');
})->with('reranking-providers');

test('collections can be reranked using string field', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $items = collection([
        ['id' => 1, 'content' => 'Django is a Python web framework.'],
        ['id' => 2, 'content' => 'CakePHP is a PHP web application framework.'],
        ['id' => 3, 'content' => 'React is a JavaScript library.'],
    ]);

    $reranked = RerankCollection::rerank($items, by: 'content', query: 'PHP frameworks', limit: 2, provider: $provider);

    expect($reranked)->toHaveCount(2)
        ->and($reranked->first()['id'])->toEqual(2);
})->with('reranking-providers');

test('collections can be reranked using array fields', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $items = collection([
        ['id' => 1, 'title' => 'Django Guide', 'body' => 'Learn Python web development.'],
        ['id' => 2, 'title' => 'CakePHP Guide', 'body' => 'Learn PHP web development.'],
        ['id' => 3, 'title' => 'React Guide', 'body' => 'Learn JavaScript UI development.'],
    ]);

    $reranked = RerankCollection::rerank($items, by: ['title', 'body'], query: 'PHP frameworks', limit: 2, provider: $provider);

    expect($reranked)->toHaveCount(2)
        ->and($reranked->first()['id'])->toEqual(2);
})->with('reranking-providers');

test('collections can be reranked using closure', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $items = collection([
        ['id' => 1, 'title' => 'Django', 'body' => 'Python web framework.'],
        ['id' => 2, 'title' => 'CakePHP', 'body' => 'PHP web framework.'],
        ['id' => 3, 'title' => 'React', 'body' => 'JavaScript library.'],
    ]);

    $reranked = RerankCollection::rerank(
        $items,
        fn(array $item): string => $item['title'] . ': ' . $item['body'],
        'PHP frameworks',
        limit: 2,
        provider: $provider,
    );

    expect($reranked)->toHaveCount(2)
        ->and($reranked->first()['id'])->toEqual(2);
})->with('reranking-providers');
