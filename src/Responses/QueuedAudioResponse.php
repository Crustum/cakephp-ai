<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Crustum\Ai\Responses\Trait\HasQueuedResponseCallbacksTrait;

/**
 * Queued audio response handle with completion callbacks.
 */
class QueuedAudioResponse
{
    use HasQueuedResponseCallbacksTrait;

    /**
     * Constructor.
     *
     * @param mixed $dispatchable Pending dispatch handle
     */
    public function __construct(public mixed $dispatchable = null)
    {
    }
}
