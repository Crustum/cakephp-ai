<?php
declare(strict_types=1);

namespace Crustum\Ai\Classification;

use Crustum\Ai\Contracts\Question;
use InvalidArgumentException;

/**
 * Choice classification question.
 *
 * A single-choice question whose answer is one of the given options.
 */
final readonly class Choice implements Question
{
    /**
     * Create a new single-choice question whose answer is one of the given options.
     *
     * @param array<string, mixed>|string $instructions Question instructions
     * @param array<array-key, string|array<string, mixed>|null> $options Option names mapped to an optional description
     * @throws \InvalidArgumentException if fewer than two options are given or any option name is not a string
     */
    public function __construct(
        public string|array $instructions,
        public array $options,
    ) {
        if (count($options) < 2) {
            throw new InvalidArgumentException('A choice question requires at least two options.');
        }

        foreach (array_keys($options) as $option) {
            if (!is_string($option)) {
                throw new InvalidArgumentException('Choice options must be keyed by option name.');
            }
        }
    }

    /**
     * Get the question as a provider-neutral array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'choice',
            'instructions' => $this->instructions,
            'options' => $this->options,
        ];
    }
}
