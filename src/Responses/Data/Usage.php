<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;

/**
 * Usage
 *
 * Tracks token usage for AI API requests.
 */
readonly class Usage implements JsonSerializable
{
    /**
     * Constructor
     *
     * @param int $inputTokens Total input tokens.
     * @param int $outputTokens Total output tokens.
     */
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {
    }

    /**
     * Get the total number of input and output tokens.
     *
     * @return int
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, int|null> The array representation.
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     *
     * @return array<string, int|null> The JSON serializable data.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
