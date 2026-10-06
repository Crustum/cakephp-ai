<?php
declare(strict_types=1);

namespace Crustum\Ai\Http\Stream;

use Cake\Http\Response\AbstractStreamResponse;

/**
 * Agent User Interaction Protocol Stream Response
 *
 * Streams Agent User Interaction protocol events to the client using CakePHP's
 * AbstractStreamResponse which handles output buffer flushing properly via
 * CallbackStream (non-seekable body).
 */
class AgentUserInteractionProtocolStreamResponse extends AbstractStreamResponse
{
    /**
     * Get the content type for the AG-UI protocol stream response.
     *
     * @return string
     */
    protected function contentType(): string
    {
        return 'text/event-stream';
    }

    /**
     * Apply the streaming headers required for the AG-UI protocol.
     *
     * @return void
     */
    protected function applyStreamingHeaders(): void
    {
        $this->_setHeader('Content-Type', 'text/event-stream');
        $this->_setHeader('Cache-Control', 'no-cache, no-transform');
        $this->_setHeader('X-Accel-Buffering', 'no');
    }

    /**
     * Stream each chunk of the AG-UI protocol payload to the client.
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
