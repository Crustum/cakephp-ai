<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Crustum\Ai\Responses\Trait\HasQueuedResponseCallbacksTrait;

/**
 * Queued transcription response handle with completion callbacks.
 */
class QueuedTranscriptionResponse
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
