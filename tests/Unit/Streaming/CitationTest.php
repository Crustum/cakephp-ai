<?php
declare(strict_types=1);

use Crustum\Ai\Responses\Data\Citation as CitationData;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Streaming\Event\Citation;
use Crustum\Ai\Streaming\Event\TextDelta;

function citation(string $url, ?string $title = null): Citation
{
    return new Citation(uniqid(), 'message-1', new UrlCitation($url, $title), time());
}

test('combine collects the sources a run cited', function (): void {
    $citations = Citation::combine([
        citation('https://book.cakephp.org/5/en', 'CakePHP Documentation'),
        citation('https://modelcontextprotocol.io', 'MCP Documentation'),
    ]);

    expect($citations)->toHaveCount(2)
        ->and($citations->first()->url)->toBe('https://book.cakephp.org/5/en')
        ->and($citations->first()->title)->toBe('CakePHP Documentation');
});

test('combine keeps every mention, the way a generated response does', function (): void {
    $citations = Citation::combine([
        citation('https://book.cakephp.org/5/en', 'CakePHP Documentation'),
        citation('https://modelcontextprotocol.io', 'MCP Documentation'),
        citation('https://book.cakephp.org/5/en', 'CakePHP Documentation'),
    ]);

    expect($citations)->toHaveCount(3)
        ->and(array_map(fn(CitationData $citation): string => $citation->url, $citations->toList()))->toBe([
            'https://book.cakephp.org/5/en',
            'https://modelcontextprotocol.io',
            'https://book.cakephp.org/5/en',
        ]);
});

test('combine ignores events that are not citations', function (): void {
    $citations = Citation::combine([
        new TextDelta(uniqid(), 'message-1', 'Crustum MCP ships a server.', time()),
        citation('https://modelcontextprotocol.io'),
    ]);

    expect($citations)->toHaveCount(1);
});

test('combine returns nothing when the run cited nothing', function (): void {
    expect(Citation::combine([
        new TextDelta(uniqid(), 'message-1', 'It is 12°C.', time()),
    ]))->toBeEmpty();
});
