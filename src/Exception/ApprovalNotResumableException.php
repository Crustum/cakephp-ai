<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

/**
 * Thrown when a non-conversational agent pauses for tool approval.
 */
class ApprovalNotResumableException extends AiException
{
    /**
     * Create a new approval not resumable exception.
     */
    public static function make(): self
    {
        return new self('Tool approval requires a conversational agent so pending tool calls can be resumed from history.');
    }
}
