<?php
declare(strict_types=1);

namespace Crustum\Ai\Http\Stream;

use Closure;
use Crustum\Ai\Exception\StoppedException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Stop-aware PSR-7 stream decorator.
 *
 * Polls a stop predicate (at most once per poll interval) on every
 * `read()`, `getContents()` and `eof()` call. When the predicate reports
 * a stop, the wrapped transport is closed and a `StoppedException` is
 * thrown. Checking in `eof()` matters: a stop requested after the
 * transport already reached EOF is still consumed instead of surfacing
 * as a normal end of stream.
 */
final class StoppableStream implements StreamInterface
{
    private float $nextPoll = 0.0;

    /**
     * @param \Psr\Http\Message\StreamInterface $stream Stream to wrap
     * @param \Closure(): bool $shouldStop Stop predicate
     * @param float $pollInterval Minimum seconds between predicate polls
     */
    public function __construct(
        private StreamInterface $stream,
        private Closure $shouldStop,
        private float $pollInterval = 0.01,
    ) {
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
        $this->stream->close();
    }

    /**
     * @inheritDoc
     */
    public function detach()
    {
        return $this->stream->detach();
    }

    /**
     * @inheritDoc
     */
    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    /**
     * @inheritDoc
     */
    public function tell(): int
    {
        return $this->stream->tell();
    }

    /**
     * @inheritDoc
     */
    public function eof(): bool
    {
        $this->throwIfStopped();

        return $this->stream->eof();
    }

    /**
     * @inheritDoc
     */
    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    /**
     * @inheritDoc
     */
    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    /**
     * @inheritDoc
     */
    public function rewind(): void
    {
        $this->stream->rewind();
    }

    /**
     * @inheritDoc
     */
    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    /**
     * @inheritDoc
     */
    public function write($string): int
    {
        return $this->stream->write($string);
    }

    /**
     * @inheritDoc
     */
    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    /**
     * @inheritDoc
     */
    public function read($length): string
    {
        $this->throwIfStopped();

        return $this->stream->read($length);
    }

    /**
     * @inheritDoc
     */
    public function getContents(): string
    {
        $this->throwIfStopped();

        return $this->stream->getContents();
    }

    /**
     * @inheritDoc
     */
    public function getMetadata($key = null)
    {
        return $key === null ? $this->stream->getMetadata() : $this->stream->getMetadata($key);
    }

    /**
     * Poll the stop predicate and abort the transport when it fires.
     *
     * @return void
     * @throws \Crustum\Ai\Exception\StoppedException
     */
    private function throwIfStopped(): void
    {
        $now = hrtime(true) / 1_000_000_000;

        if ($now < $this->nextPoll) {
            return;
        }

        $this->nextPoll = $now + $this->pollInterval;

        if (!($this->shouldStop)()) {
            return;
        }

        try {
            $this->stream->close();
        } catch (Throwable) {
        }

        throw new StoppedException('The stream was stopped.');
    }
}
