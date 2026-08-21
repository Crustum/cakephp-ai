<?php
declare(strict_types=1);

namespace Crustum\Ai\Messages;

use Cake\Collection\CollectionInterface;

/**
 * Tool Result Message Class
 *
 * Represents a message containing results from tool executions.
 */
class ToolResultMessage extends Message
{
    /**
     * The tool results.
     */
    public CollectionInterface $toolResults;

    /**
     * Create a new tool result message instance.
     *
     * @param \Cake\Collection\CollectionInterface $toolResults The tool results
     */
    public function __construct(CollectionInterface $toolResults)
    {
        parent::__construct('tool_result', content: null);

        $this->toolResults = $toolResults;
    }
}
