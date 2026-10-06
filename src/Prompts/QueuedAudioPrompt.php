<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Crustum\Ai\Enums\Lab;

/**
 * Queued audio generation prompt.
 *
 * Represents an audio generation request that has been queued
 * for asynchronous processing.
 */
class QueuedAudioPrompt
{
    /**
     * Constructor.
     *
     * @param string $text The text to convert to audio
     * @param string $voice The voice identifier
     * @param string|null $instructions Voice instructions
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider specification
     * @param string|null $model Model identifier
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     */
    public function __construct(
        public readonly string $text,
        public readonly string $voice,
        public readonly ?string $instructions,
        public readonly Lab|array|string|null $provider,
        public readonly ?string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if the text contains the given string.
     *
     * @param string $string String to search for
     * @return bool
     */
    public function contains(string $string): bool
    {
        return str_contains($this->text, $string);
    }

    /**
     * Determine if the voice is male.
     *
     * @return bool
     */
    public function isMale(): bool
    {
        return $this->voice === 'default-male';
    }

    /**
     * Determine if the voice is female.
     *
     * @return bool
     */
    public function isFemale(): bool
    {
        return $this->voice === 'default-female';
    }
}
