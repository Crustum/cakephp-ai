<?php
declare(strict_types=1);

namespace Crustum\Ai\Classification;

use Crustum\Ai\Contracts\Question;
use InvalidArgumentException;

/**
 * Boolean classification question.
 *
 * A yes / no question whose answer is the probability of "true".
 */
final readonly class Boolean implements Question
{
    /**
     * Create a new yes / no question whose answer is the probability of "true".
     *
     * @param array<string, mixed>|string $instructions Question instructions
     * @param array{true?: string, false?: string}|null $criteria Descriptions of what a yes and a no mean
     * @throws \InvalidArgumentException if the criteria describe anything but the true and false cases
     */
    public function __construct(
        public string|array $instructions,
        public ?array $criteria = null,
    ) {
        if ($criteria !== null && array_diff(array_keys($criteria), ['true', 'false']) !== []) {
            throw new InvalidArgumentException(
                'Boolean criteria may only describe the "true" and "false" cases.',
            );
        }
    }

    /**
     * Get the question as a provider-neutral array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => 'boolean',
            'instructions' => $this->instructions,
            'criteria' => $this->criteria,
        ], fn($value): bool => $value !== null);
    }
}
