<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

/**
 * Transcription Usage
 *
 * Tracks token usage for transcription requests, including the transcribed audio duration.
 */
readonly class TranscriptionUsage extends TextUsage
{
    /**
     * Constructor
     *
     * @param int $inputTokens Total input tokens.
     * @param int $outputTokens Total output tokens.
     * @param int|null $cacheReadInputTokens Subset of the input tokens read from a prompt cache, or null when unreported.
     * @param int|null $cacheWriteInputTokens Subset of the input tokens written to a prompt cache, or null when unreported.
     * @param int|null $reasoningTokens Subset of the output tokens spent on reasoning, or null when unreported.
     * @param float|null $audioSeconds Duration of the transcribed audio in seconds, or null when unreported.
     */
    public function __construct(
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?int $cacheReadInputTokens = null,
        ?int $cacheWriteInputTokens = null,
        ?int $reasoningTokens = null,
        public ?float $audioSeconds = null,
    ) {
        parent::__construct($inputTokens, $outputTokens, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningTokens);
    }

    /**
     * Create a transcription usage instance from the given usage.
     *
     * @param \Crustum\Ai\Responses\Data\TextUsage $usage Text usage
     * @param float|null $audioSeconds Duration of the transcribed audio in seconds, or null when unreported.
     * @return self
     */
    public static function from(TextUsage $usage, ?float $audioSeconds = null): self
    {
        return new self(
            $usage->inputTokens,
            $usage->outputTokens,
            $usage->cacheReadInputTokens,
            $usage->cacheWriteInputTokens,
            $usage->reasoningTokens,
            $audioSeconds,
        );
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, int|float|null> The array representation.
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'audio_seconds' => $this->audioSeconds,
        ];
    }
}
