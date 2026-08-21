<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;

/**
 * Usage
 *
 * Tracks token usage for AI API requests.
 */
class Usage implements JsonSerializable
{
    /**
     * Constructor
     *
     * @param int $promptTokens Number of tokens in the prompt/input.
     * @param int $completionTokens Number of tokens in the completion/output.
     * @param int $cacheWriteInputTokens Number of tokens written to the cache.
     * @param int $cacheReadInputTokens Number of tokens read from the cache.
     * @param int $reasoningTokens Number of tokens used for reasoning.
     */
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $cacheWriteInputTokens = 0,
        public int $cacheReadInputTokens = 0,
        public int $reasoningTokens = 0,
    ) {
    }

    /**
     * Add the given usage to the current usage and return a new usage instance.
     *
     * @param \Crustum\Ai\Responses\Data\Usage $usage The usage to add.
     * @return \Crustum\Ai\Responses\Data\Usage A new combined usage instance.
     */
    public function add(Usage $usage): Usage
    {
        return new Usage(
            $this->promptTokens + $usage->promptTokens,
            $this->completionTokens + $usage->completionTokens,
            $this->cacheWriteInputTokens + $usage->cacheWriteInputTokens,
            $this->cacheReadInputTokens + $usage->cacheReadInputTokens,
            $this->reasoningTokens + $usage->reasoningTokens,
        );
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, int> The array representation.
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     *
     * @return array<string, int> The JSON serializable data.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
