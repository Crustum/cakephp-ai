<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use Crustum\Ai\Responses\Trait\HasRawResponseTrait;
use JsonSerializable;

/**
 * Agent step data.
 *
 * Represents a single step in an agent's execution, including
 * text output, tool calls, results, and metadata.
 */
class Step implements JsonSerializable
{
    use HasRawResponseTrait;

    /**
     * Constructor.
     *
     * @param string $text Step text output
     * @param array<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls Tool calls made
     * @param array<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Tool results
     * @param \Crustum\Ai\Responses\Data\FinishReason $finishReason Reason for finishing
     * @param \Crustum\Ai\Responses\Data\TextUsage $usage Token usage
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata
     * @param string $reasoning Reasoning the step produced
     * @param array<int, array<string, mixed>> $replayBlocks Replay blocks
     * @param array<int, \Crustum\Ai\Responses\Data\ProviderToolCall> $providerToolCalls Provider-hosted tool calls
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public array $toolResults,
        public FinishReason $finishReason,
        public TextUsage $usage,
        public Meta $meta,
        public string $reasoning,
        public array $replayBlocks,
        public array $providerToolCalls = [],
    ) {
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'tool_calls' => $this->toolCalls,
            'tool_results' => $this->toolResults,
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage,
            'meta' => $this->meta,
            'reasoning' => $this->reasoning,
            'replay_blocks' => $this->replayBlocks,
            'provider_tool_calls' => $this->providerToolCalls,
        ];
    }

    /**
     * Get the JSON serializable representation.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
