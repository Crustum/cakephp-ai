<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\Usage;
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
     * @param \Crustum\Ai\Responses\Data\Usage $usage Token usage information.
     * @param \Crustum\Ai\Responses\Data\Meta $meta Response metadata.
     * @param array<string, mixed>|null $structured Structured output data if requested.
     * @param string|null $continuationToken Provider-specific token for continuing generation.
     * @param array<int, array<string, mixed>> $providerContentBlocks Raw content blocks from the provider.
     * @param array<int, \Crustum\Ai\Approvals\PendingApproval> $pendingApprovals Pending tool approvals.
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public FinishReason $finishReason,
        public Usage $usage,
        public Meta $meta,
        public ?array $structured = null,
        public ?string $continuationToken = null,
        public array $providerContentBlocks = [],
        public array $pendingApprovals = [],
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
            'provider_content_blocks' => $this->providerContentBlocks,
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
