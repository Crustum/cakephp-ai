<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Countable;
use Crustum\Ai\Contracts\Providers\ClassificationProvider;

/**
 * Classification Prompt Class
 *
 * Represents a prompt for answering questions about a state.
 */
class ClassificationPrompt implements Countable
{
    /**
     * Create a new classification prompt instance.
     *
     * @param array<string, mixed>|string $state The state to classify
     * @param array<string, \Crustum\Ai\Contracts\Question> $questions The questions to answer
     * @param \Crustum\Ai\Contracts\Providers\ClassificationProvider $provider The classification provider
     * @param string $model The model identifier
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     */
    public function __construct(
        public readonly string|array $state,
        public readonly array $questions,
        public readonly ClassificationProvider $provider,
        public readonly string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if the state contains the given string.
     *
     * @param string $string The string to search for
     * @return bool
     */
    public function contains(string $string): bool
    {
        return str_contains(is_string($this->state) ? $this->state : (string)json_encode($this->state), $string);
    }

    /**
     * Determine if the prompt asks a question with the given key.
     *
     * @param string $key The question key
     * @return bool
     */
    public function asks(string $key): bool
    {
        return array_key_exists($key, $this->questions);
    }

    /**
     * Get the number of questions in the prompt.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->questions);
    }
}
