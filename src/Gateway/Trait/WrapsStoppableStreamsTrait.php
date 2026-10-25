<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use Closure;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Http\Stream\StoppableStream;
use Psr\Http\Message\StreamInterface;

/**
 * Wraps Stoppable Streams Trait
 *
 * Wraps a provider stream body so a stop predicate aborts the transport
 * read with a `StoppedException`. Steps without a predicate pass the body
 * through untouched.
 */
trait WrapsStoppableStreamsTrait
{
    /**
     * Wrap the given stream body when the step carries a stop predicate.
     *
     * @param \Psr\Http\Message\StreamInterface $streamBody Provider stream body
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Step context
     * @return \Psr\Http\Message\StreamInterface
     */
    protected function stoppableBody(StreamInterface $streamBody, StepContext $stepContext): StreamInterface
    {
        $stopCheck = $stepContext->stopCheck;

        if (!$stopCheck instanceof Closure) {
            return $streamBody;
        }

        return new StoppableStream($streamBody, $stopCheck);
    }
}
