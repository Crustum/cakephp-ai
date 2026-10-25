<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use Closure;
use Crustum\Ai\Exception\StoppedException;
use Generator;

/**
 * Parses Server Sent Events Trait
 *
 * Parses SSE stream bodies into decoded JSON event payloads.
 */
trait ParsesServerSentEventsTrait
{
    /**
     * Parse an SSE stream body into decoded JSON data objects.
     *
     * @param object $streamBody Stream with eof() and read() methods
     * @param (\Closure(): bool)|null $shouldStop Stop predicate polled while reading
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    protected function parseServerSentEvents(object $streamBody, ?Closure $shouldStop = null): Generator
    {
        $nextPoll = 0.0;

        while (!$streamBody->eof()) {
            if ($shouldStop instanceof Closure) {
                $now = hrtime(true) / 1_000_000_000;

                if ($now >= $nextPoll) {
                    $nextPoll = $now + 0.01;

                    if ($shouldStop()) {
                        if (method_exists($streamBody, 'close')) {
                            $streamBody->close();
                        }

                        throw new StoppedException('The stream was stopped.');
                    }
                }
            }

            $line = trim($this->readLine($streamBody, $shouldStop));
            if ($line === '') {
                continue;
            }

            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));

            if ($data === '[DONE]') {
                return;
            }

            $decoded = json_decode($data, true);

            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                yield $decoded;
            }
        }
    }

    /**
     * Read a single line from the stream, byte by byte, to prevent event batching.
     *
     * @param object $streamBody Stream with eof() and read() methods
     * @param (\Closure(): bool)|null $shouldStop Stop predicate polled while reading
     * @return string
     */
    protected function readLine(object $streamBody, ?Closure $shouldStop = null): string
    {
        $buffer = '';
        $nextPoll = 0.0;

        while (!$streamBody->eof()) {
            if ($shouldStop instanceof Closure) {
                $now = hrtime(true) / 1_000_000_000;

                if ($now >= $nextPoll) {
                    $nextPoll = $now + 0.01;

                    if ($shouldStop()) {
                        if (method_exists($streamBody, 'close')) {
                            $streamBody->close();
                        }

                        throw new StoppedException('The stream was stopped.');
                    }
                }
            }

            $byte = $streamBody->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }
}
