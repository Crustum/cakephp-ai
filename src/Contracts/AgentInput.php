<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Messages\UserMessage;

/**
 * Agent Input Interface
 *
 * Represents input to an agent that may contain a user message,
 * tool approval decisions, or both.
 */
interface AgentInput
{
    /**
     * Get the newest user message, if the input contains one.
     *
     * @return \Crustum\Ai\Messages\UserMessage|null
     */
    public function message(): ?UserMessage;

    /**
     * Get the tool approval decisions, if the input contains any.
     *
     * @return \Crustum\Ai\Approvals\Decisions|null
     */
    public function decisions(): ?Decisions;
}
