<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

use Crustum\Ai\Responses\Data;

/**
 * Tool call event.
 *
 * Fired when an AI agent calls a tool during a streaming response.
 */
class ToolCall extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param \Crustum\Ai\Responses\Data\ToolCall $toolCall Tool call data
     * @param int $timestamp Unix timestamp
     */
    public function __construct(
        public string $id,
        public Data\ToolCall $toolCall,
        public int $timestamp,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'tool_call',
            'tool_id' => $this->toolCall->id,
            'tool_name' => $this->toolCall->name,
            'arguments' => $this->toolCall->arguments,
            'reasoning_id' => $this->toolCall->reasoningId,
            'timestamp' => $this->timestamp,
        ];
    }
}
