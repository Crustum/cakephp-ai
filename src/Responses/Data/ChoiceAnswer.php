<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

/**
 * Choice Answer
 *
 * Represents a single-choice classification answer with per-option probabilities.
 */
class ChoiceAnswer extends Answer
{
    /**
     * Constructor
     *
     * @param string $choice The selected option.
     * @param array<string, float> $probabilities Probabilities keyed by option.
     * @param float|null $confidence Distribution certainty, or null when unreported.
     */
    public function __construct(
        public readonly string $choice,
        public readonly array $probabilities,
        public readonly ?float $confidence = null,
    ) {
    }

    /**
     * Get the probability of the given option.
     *
     * @param string $option The option name.
     * @return float The probability, or 0.0 when absent.
     */
    public function probabilityOf(string $option): float
    {
        return $this->probabilities[$option] ?? 0.0;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed> The array representation.
     */
    public function toArray(): array
    {
        return [
            'choice' => $this->choice,
            'probabilities' => $this->probabilities,
            'confidence' => $this->confidence,
        ];
    }
}
