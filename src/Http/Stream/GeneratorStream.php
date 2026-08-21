<?php
declare(strict_types=1);

namespace Crustum\Ai\Http\Stream;

use Generator;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Generator Stream
 *
 * Wraps a generator in a PSR-7 stream, allowing response bodies to be produced
 * lazily and streamed to the client one chunk at a time instead of being
 * buffered in memory. The generator yields string chunks that are served
 * sequentially.
 *
 * Cake's ResponseEmitter reads seekable streams in fixed-size chunks, so
 * isSeekable() reports true while rewind() is a safe no-op: a generator
 * cannot be restarted, but the emitter only rewinds once before reading.
 */
final class GeneratorStream implements StreamInterface
{
    private ?Generator $generator;

    private string $buffer = '';

    private int $position = 0;

    /**
     * Create a new generator stream instance.
     *
     * @param \Generator $generator Generator yielding string chunks
     */
    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @inheritDoc
     */
    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (RuntimeException) {
            return '';
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->generator = null;
        $this->buffer = '';
    }

    /**
     * @inheritDoc
     */
    public function detach()
    {
        $this->close();

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getSize(): ?int
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function tell(): int
    {
        return $this->position;
    }

    /**
     * @inheritDoc
     */
    public function eof(): bool
    {
        return (!$this->generator instanceof Generator || !$this->generator->valid()) && $this->buffer === '';
    }

    /**
     * @inheritDoc
     */
    public function isSeekable(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new RuntimeException('Generator streams are not seekable.');
    }

    /**
     * @inheritDoc
     */
    public function rewind(): void
    {
    }

    /**
     * @inheritDoc
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function write($string): int
    {
        throw new RuntimeException('Generator streams are read-only.');
    }

    /**
     * @inheritDoc
     */
    public function isReadable(): bool
    {
        return $this->generator instanceof Generator;
    }

    /**
     * @inheritDoc
     */
    public function read($length): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is not readable.');
        }

        if ($length <= 0) {
            throw new InvalidArgumentException('Length must be a positive integer.');
        }

        $bufferLength = strlen($this->buffer);

        while ($bufferLength < $length && $this->generator->valid()) {
            $this->buffer .= (string)$this->generator->current();
            $bufferLength = strlen($this->buffer);
            $this->generator->next();
        }

        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    /**
     * @inheritDoc
     */
    public function getContents(): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is not readable.');
        }

        while ($this->generator->valid()) {
            $this->buffer .= (string)$this->generator->current();
            $this->generator->next();
        }

        $contents = $this->buffer;
        $this->buffer = '';
        $this->position += strlen($contents);

        return $contents;
    }

    /**
     * @inheritDoc
     */
    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }
}
