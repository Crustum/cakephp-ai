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
     */
    public function __construct(
        public string $id,
        public Data\ToolResult $toolResult,
        public bool $successful,
        public ?string $error,
        public int $timestamp,
        public bool $denied = false,
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
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @inheritDoc
     */
    public function toVercelProtocolArray(): ?array
    {
        if ($this->denied) {
            return [
                'type' => 'tool-output-denied',
                'toolCallId' => $this->toolResult->id,
            ];
        }

        if (!$this->successful) {
            return [
                'type' => 'tool-output-error',
                'toolCallId' => $this->toolResult->id,
                'errorText' => $this->error ?? 'The tool call failed.',
            ];
        }

        return [
            'type' => 'tool-output-available',
            'toolCallId' => $this->toolResult->id,
            'output' => $this->toolResult->result,
        ];
    }
}
