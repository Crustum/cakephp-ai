<?php
declare(strict_types=1);

namespace Crustum\Ai\Prompts;

use Crustum\Ai\Contracts\Providers\AudioProvider;

/**
 * Audio Prompt Class
 *
 * Represents a prompt for audio generation.
 */
class AudioPrompt
{
    /**
     * Create a new audio prompt instance.
     *
     * @param string $text The text to convert to audio
     * @param string $voice The voice to use
     * @param string|null $instructions Additional instructions for the provider
     * @param \Crustum\Ai\Contracts\Providers\AudioProvider $provider The audio provider
     * @param string $model The model identifier
     * @param int $timeout The timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     */
    public function __construct(
        public readonly string $text,
        public readonly string $voice,
        public readonly ?string $instructions,
        public readonly AudioProvider $provider,
        public readonly string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if the text contains the given string.
     *
     * @param string $string The string to search for
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
