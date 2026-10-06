<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

/**
 * Text Usage
 *
 * Tracks token usage for text generation requests, including cached and reasoning tokens.
 */
readonly class TextUsage extends Usage
{
    /**
     * Constructor
     *
     * @param int $inputTokens Total input tokens, including any cached or cache-written tokens.
     * @param int $outputTokens Total output tokens, including any reasoning tokens.
     * @param int|null $cacheReadInputTokens Subset of the input tokens read from a prompt cache, or null when unreported.
     * @param int|null $cacheWriteInputTokens Subset of the input tokens written to a prompt cache, or null when unreported.
     * @param int|null $reasoningTokens Subset of the output tokens spent on reasoning, or null when unreported.
     */
    public function __construct(
        int $inputTokens = 0,
        int $outputTokens = 0,
        public ?int $cacheReadInputTokens = null,
        public ?int $cacheWriteInputTokens = null,
        public ?int $reasoningTokens = null,
    ) {
        parent::__construct($inputTokens, $outputTokens);
    }

    /**
     * Reconstruct an instance from a previously serialized toArray() payload.
     *
     * @param array<string, mixed> $data Serialized usage data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            inputTokens: (int)($data['input_tokens'] ?? 0),
            outputTokens: (int)($data['output_tokens'] ?? 0),
            cacheReadInputTokens: isset($data['cache_read_input_tokens']) ? (int)$data['cache_read_input_tokens'] : null,
            cacheWriteInputTokens: isset($data['cache_write_input_tokens']) ? (int)$data['cache_write_input_tokens'] : null,
            reasoningTokens: isset($data['reasoning_tokens']) ? (int)$data['reasoning_tokens'] : null,
        );
    }

    /**
     * Get the input tokens that were neither read from nor written to a prompt cache.
     *
     * @return int
     */
    public function uncachedInputTokens(): int
    {
        return $this->inputTokens - ($this->cacheReadInputTokens ?? 0) - ($this->cacheWriteInputTokens ?? 0);
    }

    /**
     * Add the given usage to the current usage and return a new usage instance.
     *
     * @param \Crustum\Ai\Responses\Data\TextUsage $usage The usage to add.
     * @return \Crustum\Ai\Responses\Data\TextUsage A new combined usage instance.
     */
    public function add(TextUsage $usage): TextUsage
    {
        return new TextUsage(
            $this->inputTokens + $usage->inputTokens,
            $this->outputTokens + $usage->outputTokens,
            static::sum($this->cacheReadInputTokens, $usage->cacheReadInputTokens),
            static::sum($this->cacheWriteInputTokens, $usage->cacheWriteInputTokens),
            static::sum($this->reasoningTokens, $usage->reasoningTokens),
        );
    }

    /**
     * Sum two optional counts, preserving null when neither was reported.
     *
     * @param int|null $a First count
     * @param int|null $b Second count
     * @return int|null
     */
    protected static function sum(?int $a, ?int $b): ?int
    {
        return $a === null && $b === null ? null : ($a ?? 0) + ($b ?? 0);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, int|null> The array representation.
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
