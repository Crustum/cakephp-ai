<?php
declare(strict_types=1);

namespace Crustum\Ai\Http\Stream;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Transparent stream tee — captures all reads as a side-effect.
 *
 * Wraps any PSR-7 stream. Every `read()`/`getContents()` call passes data
 * through to the caller AND stores a copy in an internal buffer. When the
 * wrapped stream reaches EOF, the `onComplete` callback fires once with the
 * complete captured content.
 *
 * This lets monitoring record the full response body of a streamed request
 * without ever interrupting or buffering ahead of the live stream: the
 * capture is a side-effect of the reads the consumer was going to make
 * anyway.
 */
final class TeeStream implements StreamInterface
{
    private StreamInterface $stream;

    private string $buffer = '';

    /**
     * @var callable|null
     */
    private $onComplete;

    private bool $fired = false;

    /**
     * Create a new tee stream instance.
     *
     * @param \Psr\Http\Message\StreamInterface $stream Stream to wrap
     */
    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    /**
     * Register a callback fired once when the wrapped stream reaches EOF.
     *
     * The callback receives the complete captured content. Exceptions thrown
     * by the callback are swallowed: recording must never break streaming.
     *
     * @param callable(string): void $callback Receives the captured content
     * @return void
     */
    public function onComplete(callable $callback): void
    {
        $this->onComplete = $callback;
    }

    /**
     * Get the content captured so far.
     *
     * @return string
     */
    public function getCaptured(): string
    {
        return $this->buffer;
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
        return $this->stream->eof();
    }

    /**
     * @inheritDoc
     */
    public function isSeekable(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new RuntimeException('Tee streams are not seekable.');
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
        throw new RuntimeException('Tee streams are read-only.');
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
        $data = $this->stream->read($length);

        if ($data !== '') {
            $this->buffer .= $data;
        }

        $this->checkEof();

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function getContents(): string
    {
        $data = $this->stream->getContents();

        if ($data !== '') {
            $this->buffer .= $data;
        }

        $this->checkEof();

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function getMetadata($key = null)
    {
        return $key === null ? $this->stream->getMetadata() : $this->stream->getMetadata($key);
    }

    /**
     * Fire the completion callback once the wrapped stream reaches EOF.
     *
     * @return void
     */
    private function checkEof(): void
    {
        if ($this->fired || !$this->stream->eof() || $this->onComplete === null) {
            return;
        }

        $this->fired = true;

        try {
            ($this->onComplete)($this->buffer);
        } catch (Throwable) {
        }
    }
}
