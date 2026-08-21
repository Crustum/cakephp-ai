<?php
declare(strict_types=1);

use Crustum\Ai\Http\Stream\GeneratorStream;

/**
 * Build a generator stream from the given chunks.
 *
 * @param array<int, string> $chunks Chunks to yield
 */
function generatorStream(array $chunks): GeneratorStream
{
    return new GeneratorStream((function () use ($chunks): Generator {
        yield from $chunks;
    })());
}

test('getContents returns the concatenated generator output', function (): void {
    $stream = generatorStream(['Hello ', 'world', '!']);

    expect($stream->getContents())->toBe('Hello world!');
});

test('getContents returns an empty string once the generator is drained', function (): void {
    $stream = generatorStream(['a', 'b']);

    expect($stream->getContents())->toBe('ab')
        ->and($stream->getContents())->toBe('')
        ->and($stream->eof())->toBeTrue();
});

test('read returns the exact number of requested bytes', function (): void {
    $stream = generatorStream(['abcdef']);

    expect($stream->read(3))->toBe('abc')
        ->and($stream->read(3))->toBe('def')
        ->and($stream->eof())->toBeTrue();
});

test('read spans generator chunk boundaries', function (): void {
    $stream = generatorStream(['ab', 'cde', 'f']);

    expect($stream->read(2))->toBe('ab')
        ->and($stream->read(2))->toBe('cd')
        ->and($stream->read(2))->toBe('ef')
        ->and($stream->read(2))->toBe('')
        ->and($stream->eof())->toBeTrue();
});

test('read returns the remaining content once the generator is exhausted', function (): void {
    $stream = generatorStream(['abc']);

    expect($stream->read(10))->toBe('abc')
        ->and($stream->read(10))->toBe('')
        ->and($stream->eof())->toBeTrue();
});

test('tell reports the number of bytes consumed', function (): void {
    $stream = generatorStream(['Hello ', 'world!']);

    $stream->read(4);

    expect($stream->tell())->toBe(4);

    $stream->read(3);
    expect($stream->tell())->toBe(7);

    $stream->getContents();
    expect($stream->tell())->toBe(12);
});

test('eof reports false until the stream is fully drained', function (): void {
    $stream = generatorStream(['abc']);

    expect($stream->eof())->toBeFalse();

    $stream->getContents();

    expect($stream->eof())->toBeTrue();
});

test('reads the stream in chunks like the Cake response emitter', function (): void {
    $stream = generatorStream(['chunk-one-', 'chunk-two-', 'chunk-three']);

    $output = '';
    $stream->rewind();

    while (!$stream->eof()) {
        $output .= $stream->read(8);
    }

    expect($output)->toBe('chunk-one-chunk-two-chunk-three');
});

test('the generator is consumed lazily as content is read', function (): void {
    $stream = generatorStream(['abc', 'def', 'ghi']);

    expect($stream->read(1))->toBe('a')
        ->and($stream->eof())->toBeFalse()
        ->and($stream->getContents())->toBe('bcdefghi');
});

test('string conversion returns the concatenated generator output', function (): void {
    $stream = generatorStream(['Hello ', 'world!']);

    expect((string)$stream)->toBe('Hello world!');
});

test('string conversion returns an empty string when a runtime exception is thrown', function (): void {
    $stream = new GeneratorStream((function (): Generator {
        yield 'partial';
        throw new RuntimeException('broken');
    })());

    expect((string)$stream)->toBe('');
});

test('string conversion rethrows exceptions the generator throws', function (): void {
    $stream = new GeneratorStream((function (): Generator {
        yield 'partial';
        throw new DomainException('boom');
    })());

    expect(fn(): string => (string)$stream)->toThrow(DomainException::class, 'boom');
});

test('an exception thrown by the generator propagates out of getContents', function (): void {
    $stream = new GeneratorStream((function (): Generator {
        yield 'ok';
        throw new DomainException('boom');
    })());

    expect(fn(): string => $stream->getContents())->toThrow(DomainException::class, 'boom');
});

test('close clears the buffer and makes the stream unreadable', function (): void {
    $stream = generatorStream(['abc']);

    $stream->close();

    expect($stream->isReadable())->toBeFalse()
        ->and($stream->eof())->toBeTrue()
        ->and(fn(): string => $stream->getContents())->toThrow(RuntimeException::class, 'not readable')
        ->and(fn(): string => $stream->read(1))->toThrow(RuntimeException::class, 'not readable');
});

test('detach closes the stream and returns null', function (): void {
    $stream = generatorStream(['abc']);

    expect($stream->detach())->toBeNull()
        ->and($stream->isReadable())->toBeFalse();
});

test('getSize returns null for a dynamically generated stream', function (): void {
    $stream = generatorStream(['abc']);

    expect($stream->getSize())->toBeNull();
});

test('isSeekable reports true and rewind is a no-op', function (): void {
    $stream = generatorStream(['abc']);

    expect($stream->isSeekable())->toBeTrue();

    $stream->rewind();

    expect($stream->getContents())->toBe('abc');
});

test('seek throws a runtime exception', function (): void {
    $stream = generatorStream(['abc']);

    expect(fn() => $stream->seek(0))->toThrow(RuntimeException::class, 'not seekable');
});

test('isWritable reports false and write throws a runtime exception', function (): void {
    $stream = generatorStream(['abc']);

    expect($stream->isWritable())->toBeFalse()
        ->and(fn(): int => $stream->write('data'))->toThrow(RuntimeException::class, 'read-only');
});

test('read with a non-positive length throws an invalid argument exception', function (): void {
    $stream = generatorStream(['abc']);

    expect(fn(): string => $stream->read(0))->toThrow(InvalidArgumentException::class, 'positive integer')
        ->and(fn(): string => $stream->read(-1))->toThrow(InvalidArgumentException::class, 'positive integer');
});

test('getMetadata returns an empty array or null', function (): void {
    $stream = generatorStream(['abc']);

    expect($stream->getMetadata())->toBe([])
        ->and($stream->getMetadata('mode'))->toBeNull();
});
