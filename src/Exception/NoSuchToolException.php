<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

/**
 * Exception thrown when model tries to call an unavailable tool.
 *
 * Occurs when an AI model attempts to invoke a tool that hasn't
 * been registered or doesn't exist in the current context.
 */
class NoSuchToolException extends AiException
{
    /**
     * Constructor.
     *
     * @param string $toolName The name of the tool that was not found
     */
    public function __construct(public readonly string $toolName)
    {
        parent::__construct(sprintf("Model tried to call unavailable tool '%s'.", $toolName));
    }
}
