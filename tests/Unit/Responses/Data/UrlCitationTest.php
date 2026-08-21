<?php
declare(strict_types=1);

use Crustum\Ai\Responses\Data\UrlCitation;

test('url citation stores url and title', function (): void {
    $citation = new UrlCitation('https://example.com', 'Example');

    expect($citation->url)->toBe('https://example.com')
        ->and($citation->title)->toBe('Example')
        ->and($citation->startIndex)->toBeNull()
        ->and($citation->endIndex)->toBeNull();
});

test('url citation stores span indices when provided', function (): void {
    $citation = new UrlCitation('https://example.com', 'Example', startIndex: 12, endIndex: 45);

    expect($citation->startIndex)->toBe(12)
        ->and($citation->endIndex)->toBe(45);
});

test('url citation to array returns all fields', function (): void {
    $citation = new UrlCitation('https://cakephp.org', 'CakePHP', startIndex: 0, endIndex: 7);

    expect($citation->toArray())->toBe([
        'url' => 'https://cakephp.org',
        'title' => 'CakePHP',
        'start_index' => 0,
        'end_index' => 7,
    ]);
});

test('url citation json serialize returns to array', function (): void {
    $citation = new UrlCitation('https://google.com');

    expect($citation->jsonSerialize())->toBe([
        'url' => 'https://google.com',
        'title' => null,
        'start_index' => null,
        'end_index' => null,
    ]);
});
