<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Gateway;

use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Responses\AudioResponse;

/**
 * Audio Gateway Interface
 *
 * Defines methods for generating audio from text using AI providers.
 */
interface AudioGateway
{
    /**
     * Generate audio from the given text.
     *
     * @param \Crustum\Ai\Contracts\Providers\AudioProvider $provider The audio provider instance
     * @param string $model The model to use for audio generation
     * @param string $text The text to convert to audio
     * @param string $voice The voice to use for audio generation
     * @param string|null $instructions Optional instructions for audio generation
     * @param int $timeout Timeout in seconds (default: 30)
     * @return \Crustum\Ai\Responses\AudioResponse
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse;
}
