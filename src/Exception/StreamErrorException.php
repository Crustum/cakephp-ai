<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

use Crustum\Ai\Streaming\Event\Error;

/**
 * Thrown when a provider reports an error inside the stream body instead of throwing.
 */
class StreamErrorException extends AiException
{
    /**
     * Constructor.
     *
     * @param \Crustum\Ai\Streaming\Event\Error|null $error The provider's error event
     */
    public function __construct(public readonly ?Error $error = null)
    {
        parent::__construct($error instanceof Error ? $error->message : 'The provider ended the stream without completing the step.');
    }
}
