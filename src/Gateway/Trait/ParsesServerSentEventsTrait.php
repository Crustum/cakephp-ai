<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

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
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    protected function parseServerSentEvents(object $streamBody): Generator
    {
        while (!$streamBody->eof()) {
            $line = trim($this->readLine($streamBody));
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
     * @return string
     */
    protected function readLine(object $streamBody): string
    {
        $buffer = '';

        while (!$streamBody->eof()) {
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
