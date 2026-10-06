<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Crustum\Ai\Contracts\PendingDispatchInterface;
use Crustum\Ai\Responses\Trait\HasQueuedResponseCallbacksTrait;

/**
 * Queued embeddings response handle with completion callbacks.
 *
 * @mixin \Crustum\Ai\Contracts\PendingDispatchInterface
 */
class QueuedEmbeddingsResponse
{
    use HasQueuedResponseCallbacksTrait;

    /**
     * Constructor.
     *
     * @param \Crustum\Ai\Contracts\PendingDispatchInterface $dispatchable Pending dispatch handle
     */
    public function __construct(public PendingDispatchInterface $dispatchable)
    {
    }

    /**
     * Proxy missing method calls to the pending dispatch instance.
     *
     * @param string $method Method name
     * @param array<mixed> $arguments Method arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->dispatchable->{$method}(...$arguments);
    }
}
