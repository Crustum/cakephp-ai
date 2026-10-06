<?php
declare(strict_types=1);

namespace Crustum\Ai\Http\Stream;

use Cake\Http\Response\AbstractStreamResponse;
use Crustum\Ai\Exception\InsufficientCreditsException;
use Crustum\Ai\Exception\ProviderConnectionException;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Streaming\Event\Error;
use Throwable;

/**
 * Event Stream Response
 *
 * Streams Server-Sent Events (SSE) to the client using CakePHP's
 * AbstractStreamResponse which handles output buffer flushing properly
 * via CallbackStream (non-seekable body).
 *
 * @see \Cake\Http\Response\AbstractStreamResponse
 */
class EventStreamResponse extends AbstractStreamResponse
{
    /**
     * Get the content type for the event stream response.
     *
     * @return string
     */
    protected function contentType(): string
    {
        return 'text/event-stream';
    }

    /**
     * Apply the streaming headers required for Server-Sent Events.
     *
     * Delegates to the parent streaming headers, then disables caching and
     * forces implicit output flushing so SSE frames reach the client instantly.
     *
     * @return void
     */
    protected function applyStreamingHeaders(): void
    {
        parent::applyStreamingHeaders();
        $this->_setHeader('Cache-Control', 'no-cache, no-transform');
        $this->_setHeader('X-Accel-Buffering', 'no');
        ob_implicit_flush(true);
    }

    /**
     * Stream each event to the client as a Server-Sent Event frame.
     *
     * Iterates the data payload, writes each event as `data: <event>\n\n`,
     * terminates the stream with a `[DONE]` frame, and flushes the output
     * buffers.
     *
     * A failure that reaches this layer means any provider failover has
     * already been exhausted; it is surfaced as an `Error` event instead of
     * crashing the already-started stream (headers are sent before streaming
     * begins). If the gateway already streamed a provider `Error` event for
     * this same failure, a duplicate frame is avoided.
     *
     * @return void
     */
    protected function streamData(): void
    {
        $invocationId = $this->resolveInvocationId();

        try {
            foreach ($this->data as $event) {
                $this->outputAndFlush('data: ' . $event . "\n\n", force: true);
            }
        } catch (Throwable $throwable) {
            if (!$throwable instanceof StreamErrorException || !$throwable->error instanceof Error) {
                $this->outputAndFlush($this->errorFrame($throwable, $invocationId), force: true);
            }
        }

        $this->outputAndFlush("data: [DONE]\n\n", force: true);
        $this->flushOutputBuffers();
    }

    /**
     * Resolve the invocation ID from the streamed payload, if available.
     *
     * @return string|null
     */
    private function resolveInvocationId(): ?string
    {
        if ($this->data instanceof StreamableAgentResponse) {
            return $this->data->invocationId;
        }

        return null;
    }

    /**
     * Build an SSE frame for a stream failure.
     *
     * @param \Throwable $exception The failure reached during emission
     * @param string|null $invocationId The active invocation ID
     * @return string
     */
    private function errorFrame(Throwable $exception, ?string $invocationId): string
    {
        $error = $exception instanceof StreamErrorException ? $exception->error : null;

        if (!$error instanceof Error) {
            $errorType = $this->errorType($exception);
            $error = new Error(
                bin2hex(random_bytes(8)),
                $errorType,
                $errorType === 'stream_error' ? 'An error occurred.' : $exception->getMessage(),
                false,
                time(),
            );

            if ($invocationId !== null) {
                $error = $error->withInvocationId($invocationId);
            }
        }

        return 'data: ' . $error . "\n\n";
    }

    /**
     * Map a stream failure to a client-facing error type.
     *
     * @param \Throwable $exception The failure
     * @return string
     */
    private function errorType(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof RateLimitedException => 'rate_limited',
            $exception instanceof InsufficientCreditsException => 'insufficient_credits',
            $exception instanceof ProviderOverloadedException => 'provider_overloaded',
            $exception instanceof ProviderConnectionException => 'connection_error',
            default => 'stream_error',
        };
    }
}
