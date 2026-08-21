<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Trait;

use Closure;

/**
 * Provides `then` / `catch` callbacks for queued response handles.
 */
trait HasQueuedResponseCallbacksTrait
{
    /**
     * Add a callback to be executed after the job resolves.
     *
     * @param \Closure $callback Response callback
     */
    public function then(Closure $callback): self
    {
        $this->dispatchable->getJob()->then($callback);

        return $this;
    }

    /**
     * Add a callback to be executed if the job fails.
     *
     * @param \Closure $callback Failure callback
     */
    public function catch(Closure $callback): self
    {
        $this->dispatchable->getJob()->catch($callback);

        return $this;
    }
}
