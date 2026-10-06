<?php
declare(strict_types=1);

use Crustum\Ai\Http\Stream\TeeStream;
use GuzzleHttp\Psr7\Utils;

/**
 * Build a tee stream wrapping a string-backed stream.
 *
 * @param string $content Stream content
 */
function teeStream(string $content): TeeStream
{
    return new TeeStream(Utils::streamFor($content));
}

test('read passes data through and captures it', function (): void {
    $stream = teeStream('abcdef');

    expect($stream->read(2))->toBe('ab')
        ->and($stream->read(2))->toBe('cd')
        ->and($stream->getCaptured())->toBe('abcd');
});

test('getContents passes data through and captures it', function (): void {
    $stream = teeStream('abcdef');

    $stream->read(2);

    expect($stream->getContents())->toBe('cdef')
        ->and($stream->getCaptured())->toBe('abcdef');
});

test('the completion callback fires once with the full captured content at EOF', function (): void {
    $captured = [];
    $stream = teeStream('abcdef');
    $stream->onComplete(function (string $body) use (&$captured): void {
        $captured[] = $body;
    });

    $stream->read(2);

    expect($captured)->toBe([]);

    $stream->getContents();
    expect($captured)->toBe(['abcdef']);

    $stream->read(2);
    $stream->getContents();

    expect($captured)->toBe(['abcdef']);
});

test('the completion callback fires when the stream is read in chunks', function (): void {
    $captured = [];
    $stream = teeStream('abcdef');
    $stream->onComplete(function (string $body) use (&$captured): void {
        $captured[] = $body;
    });

    $output = '';
    while (!$stream->eof()) {
        $output .= $stream->read(1);
    }

    expect($output)->toBe('abcdef')
        ->and($captured)->toBe(['abcdef']);
});

test('the completion callback is not fired before EOF', function (): void {
    $fired = false;
    $stream = teeStream('abcdef');
    $stream->onComplete(function (string $body) use (&$fired): void {
        $fired = true;
    });

    $stream->read(2);

    expect($fired)->toBeFalse();
});

test('an exception thrown by the completion callback does not break reading', function (): void {
    $stream = teeStream('abcdef');
    $stream->onComplete(function (string $body): void {
        throw new RuntimeException('recording failed');
    });

    expect($stream->read(2))->toBe('ab')
        ->and($stream->getContents())->toBe('cdef')
        ->and($stream->eof())->toBeTrue();
});

test('the completion callback receives the full capture and the buffer is retained', function (): void {
    $callbackBody = null;
    $stream = teeStream('abc');
    $stream->onComplete(function (string $body) use (&$callbackBody): void {
        $callbackBody = $body;
    });

    expect($stream->getContents())->toBe('abc');

    expect($callbackBody)->toBe('abc')
        ->and($stream->getCaptured())->toBe('abc');
});

test('getCaptured returns an empty string before any read', function (): void {
    expect(teeStream('abc')->getCaptured())->toBe('');
});

test('string conversion returns the remaining content and captures it', function (): void {
    $stream = teeStream('abcdef');
    $stream->read(2);

    expect((string)$stream)->toBe('cdef')
        ->and($stream->getCaptured())->toBe('abcdef');
});

test('tell and eof delegate to the wrapped stream', function (): void {
    $stream = teeStream('abcdef');

    expect($stream->eof())->toBeFalse()
        ->and($stream->tell())->toBe(0);

    $stream->read(4);

    expect($stream->tell())->toBe(4)
        ->and($stream->eof())->toBeFalse();

    $stream->read(2);
    $stream->read(2);

    expect($stream->tell())->toBe(6)
        ->and($stream->eof())->toBeTrue();
});

test('getSize delegates to the wrapped stream', function (): void {
    expect(teeStream('abcdef')->getSize())->toBe(6);
});

test('isReadable delegates to the wrapped stream', function (): void {
    expect(teeStream('abc')->isReadable())->toBeTrue();
});

test('isSeekable reports false and seek throws', function (): void {
    $stream = teeStream('abcdef');

    expect($stream->isSeekable())->toBeFalse()
        ->and(fn() => $stream->seek(0))->toThrow(RuntimeException::class, 'not seekable');
});

test('rewind is a no-op', function (): void {
    $stream = teeStream('abcdef');
    $stream->read(2);
    $stream->rewind();

    expect($stream->tell())->toBe(2);
});

test('isWritable reports false and write throws', function (): void {
    $stream = teeStream('abc');

    expect($stream->isWritable())->toBeFalse()
        ->and(fn(): int => $stream->write('data'))->toThrow(RuntimeException::class, 'read-only');
});

test('close delegates to the wrapped stream', function (): void {
    $stream = teeStream('abc');
    $stream->close();

    expect($stream->isReadable())->toBeFalse();
});

test('detach delegates to the wrapped stream', function (): void {
    $stream = teeStream('abc');

    expect($stream->detach())->toBeResource();
});

test('getMetadata delegates to the wrapped stream', function (): void {
    $stream = teeStream('abc');

    expect($stream->getMetadata())->toBeArray()
        ->and($stream->getMetadata('mode'))->toBeString()
        ->and($stream->getMetadata('unknown_key'))->toBeNull();
});
