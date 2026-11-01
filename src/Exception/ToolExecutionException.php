<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

use Throwable;

/**
 * Tool Execution Exception
 *
 * Wraps a tool failure so the text generation loop can recognize it and
 * return it to the model instead of ending the run.
 *
 * @internal
 */
class ToolExecutionException extends AiException
{
    /**
     * Constructor.
     *
     * @param \Throwable $exception Original tool failure
     */
    public function __construct(public readonly Throwable $exception)
    {
        parent::__construct($exception->getMessage(), previous: $exception);
    }
}
