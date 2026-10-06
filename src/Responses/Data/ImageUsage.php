<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

/**
 * Image Usage
 *
 * Tracks token usage for image generation requests, including image token details.
 */
readonly class ImageUsage extends TextUsage
{
    /**
     * Constructor
     *
     * @param int $inputTokens Total input tokens.
     * @param int $outputTokens Total output tokens.
     * @param int|null $cacheReadInputTokens Subset of the input tokens read from a prompt cache, or null when unreported.
     * @param int|null $cacheWriteInputTokens Subset of the input tokens written to a prompt cache, or null when unreported.
     * @param int|null $reasoningTokens Subset of the output tokens spent on reasoning, or null when unreported.
     * @param int|null $imageInputTokens Subset of the input tokens billed for images, or null when unreported.
     * @param int|null $imageOutputTokens Subset of the output tokens billed for generated images, or null when unreported.
     */
    public function __construct(
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?int $cacheReadInputTokens = null,
        ?int $cacheWriteInputTokens = null,
        ?int $reasoningTokens = null,
        public ?int $imageInputTokens = null,
        public ?int $imageOutputTokens = null,
    ) {
        parent::__construct($inputTokens, $outputTokens, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningTokens);
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
            'image_input_tokens' => $this->imageInputTokens,
            'image_output_tokens' => $this->imageOutputTokens,
        ];
    }
}
