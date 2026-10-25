<?php
declare(strict_types=1);

use Crustum\Ai\Exception\StoppedException;
use Crustum\Ai\Http\GuzzleHttpClientAdapter;
use Crustum\Ai\Http\Stream\StoppableStream;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzlePsr7Response;
use GuzzleHttp\Psr7\Utils;

test('a quiet predicate leaves the stream untouched', function (): void {
    $stream = new StoppableStream(Utils::streamFor('hello'), fn(): bool => false, 0.0);

    expect($stream->read(5))->toBe('hello')
        ->and($stream->read(1))->toBe('')
        ->and($stream->eof())->toBeTrue();
});

test('a fired predicate closes the transport and throws', function (): void {
    $inner = Utils::streamFor('hello world');
    $stream = new StoppableStream($inner, fn(): bool => true, 0.0);

    expect(fn(): string => $stream->read(5))->toThrow(StoppedException::class)
        ->and(fn(): string => $inner->read(1))->toThrow(RuntimeException::class);
});

test('the stop is consumed even when the transport already reached EOF', function (): void {
    $stream = new StoppableStream(Utils::streamFor(''), fn(): bool => true, 0.0);

    expect(fn(): bool => $stream->eof())->toThrow(StoppedException::class);
});

test('a mid-stream stop passes the chunks read so far, then throws', function (): void {
    $calls = 0;
    $stream = new StoppableStream(Utils::streamFor('abcdef'), function () use (&$calls): bool {
        $calls++;

        return $calls > 1;
    }, 0.0);

    expect($stream->read(2))->toBe('ab')
        ->and(fn(): string => $stream->read(2))->toThrow(StoppedException::class);
});

test('the adapter aborts a streaming body when stop_check fires', function (): void {
    $adapter = new GuzzleHttpClientAdapter(30, [], HandlerStack::create(new MockHandler([
        new GuzzlePsr7Response(200, ['Content-Type' => 'text/event-stream'], "data: one\n\ndata: two\n\n"),
    ])));

    $result = $adapter->post('https://api.example.com/v1/chat', '{}', [
        'stream' => true,
        'stop_check' => fn(): bool => true,
    ]);

    expect(fn(): string => $result->getBody()->read(4))->toThrow(StoppedException::class);
});

test('the adapter reads a streaming body fully when stop_check stays quiet', function (): void {
    $adapter = new GuzzleHttpClientAdapter(30, [], HandlerStack::create(new MockHandler([
        new GuzzlePsr7Response(200, ['Content-Type' => 'text/event-stream'], 'data: one'),
    ])));

    $result = $adapter->post('https://api.example.com/v1/chat', '{}', [
        'stream' => true,
        'stop_check' => fn(): bool => false,
    ]);

    expect($result->getBody()->getContents())->toBe('data: one');
});

test('the adapter ignores stop_check for non-streaming requests', function (): void {
    $adapter = new GuzzleHttpClientAdapter(30, [], HandlerStack::create(new MockHandler([
        new GuzzlePsr7Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
    ])));

    $result = $adapter->post('https://api.example.com/v1/chat', '{}', [
        'stop_check' => fn(): bool => true,
    ]);

    expect($result->getStringBody())->toBe('{"ok":true}');
});
