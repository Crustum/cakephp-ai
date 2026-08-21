<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use Override;

/**
 * Structured agent step data.
 *
 * Extends Step to include structured output data that conforms
 * to a predefined schema.
 */
class StructuredStep extends Step
{
    /**
     * Constructor.
     *
     * @param string $text Step text output
     * @param array<string, mixed> $structured Structured output data
     * @param array<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls Tool calls made
     * @param array<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Tool results
     * @param \Crustum\Ai\Responses\Data\FinishReason $finishReason Reason for finishing
     * @param \Crustum\Ai\Responses\Data\Usage $usage Token usage
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata
     */
    public function __construct(
        string $text,
        public array $structured,
        array $toolCalls,
        array $toolResults,
        FinishReason $finishReason,
        Usage $usage,
        Meta $meta,
    ) {
        parent::__construct($text, $toolCalls, $toolResults, $finishReason, $usage, $meta);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'structured' => $this->structured];
    }

    /**
     * Get the JSON serializable representation.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
