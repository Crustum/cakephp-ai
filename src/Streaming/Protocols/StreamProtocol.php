<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Protocols;

use Cake\Http\Response;
use Cake\Log\Log;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Generator;
use Throwable;

/**
 * Stream Protocol
 *
 * Base class for streaming protocols (Vercel data stream, Agent User Interaction).
 * Subclasses map stream events to protocol parts; this class encodes the parts
 * as server-sent event lines and wraps failures in a masked terminal part.
 */
abstract class StreamProtocol
{
    /**
     * Whether the first protocol part was emitted.
     */
    protected bool $started = false;

    /**
     * Whether an error part was emitted.
     */
    protected bool $errored = false;

    /**
     * Failure that interrupted the stream, if any.
     */
    protected ?Throwable $failure = null;

    /**
     * Get the protocol parts that represent the given response's events.
     *
     * @param \Crustum\Ai\Responses\StreamableAgentResponse $response Streamable response
     * @return \Generator<int, array<string, mixed>>
     */
    abstract protected function parts(StreamableAgentResponse $response): Generator;

    /**
     * Get the protocol parts that terminate a stream interrupted by an exception.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    abstract protected function maskedErrorParts(): Generator;

    /**
     * Create an HTTP response that represents the given response using the protocol.
     *
     * @param \Crustum\Ai\Responses\StreamableAgentResponse $response Streamable response
     * @return \Cake\Http\Response
     */
    abstract public function response(StreamableAgentResponse $response): Response;

    /**
     * Stream the encoded protocol parts, masking unexpected failures.
     *
     * @param \Crustum\Ai\Responses\StreamableAgentResponse $response Streamable response
     * @return \Generator<int, string>
     */
    protected function frames(StreamableAgentResponse $response): Generator
    {
        try {
            foreach ($this->parts($response) as $part) {
                yield $this->encode($part);
            }
        } catch (Throwable $throwable) {
            if (!$throwable instanceof StreamErrorException) {
                Log::error('Stream failed: ' . $throwable->getMessage());
            }

            if (!$this->errored) {
                $this->failure = $throwable;

                foreach ($this->maskedErrorParts() as $part) {
                    yield $this->encode($part);
                }
            }
        }

        $terminator = $this->terminator();

        if ($terminator !== null) {
            yield $terminator;
        }
    }

    /**
     * Get the raw frame that terminates the stream, if any.
     *
     * @return string|null
     */
    protected function terminator(): ?string
    {
        return null;
    }

    /**
     * Encode the given protocol part as a server-sent event line.
     *
     * @param array<string, mixed> $part Protocol part
     * @return string
     */
    protected function encode(array $part): string
    {
        return 'data: ' . $this->json($part) . "\n\n";
    }

    /**
     * Encode the given value as JSON, substituting bytes a provider streamed as invalid UTF-8.
     *
     * @param mixed $value Value to encode
     * @return string
     */
    protected function json(mixed $value): string
    {
        return (string)json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
