<?php
declare(strict_types=1);

namespace Crustum\Ai\Http\Stream;

use Cake\Http\Response\AbstractStreamResponse;

/**
 * Vercel Protocol Stream Response
 *
 * Streams the Vercel AI SDK message protocol to the client using CakePHP's
 * AbstractStreamResponse which handles output buffer flushing properly via
 * CallbackStream (non-seekable body).
 *
 * @see \Cake\Http\Response\AbstractStreamResponse
 */
class VercelProtocolStreamResponse extends AbstractStreamResponse
{
    /**
     * Get the content type for the Vercel protocol stream response.
     *
     * @return string
     */
    protected function contentType(): string
    {
        return 'text/event-stream';
    }

    /**
     * Apply the streaming headers required for the Vercel AI SDK protocol.
     *
     * Sets the SSE content type, disables caching and proxy buffering, and
     * advertises the Vercel message-stream protocol version.
     *
     * @return void
     */
    protected function applyStreamingHeaders(): void
    {
        $this->_setHeader('Content-Type', 'text/event-stream');
        $this->_setHeader('Cache-Control', 'no-cache, no-transform');
        $this->_setHeader('x-vercel-ai-ui-message-stream', 'v1');
        $this->_setHeader('X-Accel-Buffering', 'no');
    }

    /**
     * Stream each chunk of the Vercel protocol payload to the client.
     *
     * Iterates the data payload and writes each chunk directly to the output,
     * flushing after every chunk, then flushes the output buffers.
     *
     * @return void
     */
    protected function streamData(): void
    {
        foreach ($this->data as $chunk) {
            $this->outputAndFlush($chunk, force: true);
        }

        $this->flushOutputBuffers();
    }
}
