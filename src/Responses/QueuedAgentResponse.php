<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Crustum\Ai\Responses\Trait\HasQueuedResponseCallbacksTrait;

/**
 * Queued agent response handle with completion callbacks.
 */
class QueuedAgentResponse
{
    use HasQueuedResponseCallbacksTrait;

    /**
     * Constructor.
     *
     * @param mixed $dispatchable Pending dispatch handle
     */
    public function __construct(protected mixed $dispatchable = null)
    {
    }
}
