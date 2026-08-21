<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

use Stringable;

/**
 * CanActAsTool Interface
 *
 * Marks a class as capable of acting as a tool for AI agents.
 * Classes implementing this interface can be used by agents to perform specific actions.
 */
interface CanActAsTool
{
    /**
     * Get the name of the tool.
     *
     * Returns a unique identifier for this tool that the AI agent will use to reference it.
     *
     * @return string The tool's name.
     */
    public function name(): string;

    /**
     * Get the description of the tool's purpose.
     *
     * Returns a human-readable description that explains what the tool does and when it should be used.
     * This description helps the AI agent decide when to invoke this tool.
     *
     * @return \Stringable|string The tool's description.
     */
    public function description(): Stringable|string;
}
