<?php
declare(strict_types=1);

namespace Crustum\Ai\Streaming\Event;

use Crustum\Ai\Responses\Data;

/**
 * Tool result event.
 *
 * Fired when a tool execution completes during a streaming response.
 */
class ToolResult extends StreamEvent
{
    /**
     * Constructor.
     *
     * @param string $id Event ID
     * @param \Crustum\Ai\Responses\Data\ToolResult $toolResult Tool result data
     * @param bool $successful Whether the tool execution was successful
     * @param string|null $error Error message if failed
     * @param int $timestamp Unix timestamp
     * @param bool $denied Whether the tool call was denied / not approved
     * @param bool $preliminary Whether the result reports unfinished output that a settled result will replace
     */
    public function __construct(
        public string $id,
        public Data\ToolResult $toolResult,
        public bool $successful,
        public ?string $error,
        public int $timestamp,
        public bool $denied = false,
        public bool $preliminary = false,
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
            'type' => 'tool_result',
            'tool_id' => $this->toolResult->id,
            'tool_name' => $this->toolResult->name,
            'result' => $this->toolResult->result,
            'successful' => $this->successful,
            'error' => $this->error,
            'denied' => $this->denied,
            ...($this->preliminary ? ['preliminary' => true] : []),
            'timestamp' => $this->timestamp,
        ];
    }
}
