<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Gateway;

/**
 * A fake PSR-7-compatible stream that tracks read behavior.
 */
class FakeStream
{
    private int $position = 0;

    public int $maxReadSize = 0;

    public function __construct(private readonly string $data)
    {
    }

    public function read(int $length): string
    {
        if ($length > $this->maxReadSize) {
            $this->maxReadSize = $length;
        }

        $chunk = substr($this->data, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    public function fullyConsumed(): bool
    {
        return $this->position >= strlen($this->data);
    }
}
