<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ProviderToolCall;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Trait\HasRawResponseTrait;
use JsonSerializable;

/**
 * Step Response
 *
 * Represents the response from a single step in multi-step text generation.
 */
class StepResponse implements JsonSerializable
{
    use HasRawResponseTrait;

    /**
     * Constructor
     *
     * @param string $text The generated text content.
     * @param array<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls Array of tool calls requested by the model.
     * @param \Crustum\Ai\Responses\Data\FinishReason $finishReason Why generation stopped.
     * @param \Crustum\Ai\Responses\Data\TextUsage $usage Token usage information.
     * @param \Crustum\Ai\Responses\Data\Meta $meta Response metadata.
     * @param array<string, mixed>|null $structured Structured output data if requested.
     * @param string|null $continuationToken Provider-specific token for continuing generation.
     * @param array<array-key, mixed> $replayBlocks Raw content blocks from the provider.
     * @param array<int, \Crustum\Ai\Approvals\PendingApproval> $pendingApprovals Pending tool approvals.
     * @param string $reasoning Reasoning the step produced.
     * @param array<int, \Crustum\Ai\Responses\Data\ProviderToolCall> $providerToolCalls Provider-hosted tool calls.
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public FinishReason $finishReason,
        public TextUsage $usage,
        public Meta $meta,
        public ?array $structured = null,
        public ?string $continuationToken = null,
        public array $replayBlocks = [],
        public array $pendingApprovals = [],
        public string $reasoning = '',
        public array $providerToolCalls = [],
    ) {
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed> The array representation.
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'structured' => $this->structured,
            'tool_calls' => array_map(fn(ToolCall $tc): array => $tc->toArray(), $this->toolCalls),
            'provider_tool_calls' => array_map(fn(ProviderToolCall $call): array => $call->toArray(), $this->providerToolCalls),
            'replay_blocks' => $this->replayBlocks,
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
            'continuation_token' => $this->continuationToken,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     *
     * @return array<string, mixed> The JSON serializable data.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
