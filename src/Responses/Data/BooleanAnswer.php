<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

/**
 * Boolean Answer
 *
 * Represents a true/false classification answer with a probability.
 */
class BooleanAnswer extends Answer
{
    /**
     * Constructor
     *
     * @param float $probability The probability that the answer is true.
     */
    public function __construct(
        public readonly float $probability,
    ) {
    }

    /**
     * Determine if the probability meets the given threshold.
     *
     * @param float $threshold The threshold to compare against.
     * @return bool Whether the answer counts as true.
     */
    public function isTrue(float $threshold = 0.5): bool
    {
        return $this->probability >= $threshold;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed> The array representation.
     */
    public function toArray(): array
    {
        return ['probability' => $this->probability];
    }
}
