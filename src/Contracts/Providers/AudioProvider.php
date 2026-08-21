<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Contracts\Gateway\AudioGateway;
use Crustum\Ai\Responses\AudioResponse;

/**
 * Audio Provider Interface
 *
 * Defines contract for providers that support audio generation (TTS) capabilities.
 */
interface AudioProvider extends Provider
{
    /**
     * Generate audio from the given text.
     *
     * @param string $text Text to convert to audio
     * @param string $voice Voice to use for audio generation
     * @param string|null $instructions Additional instructions for audio generation
     * @param string|null $model Model to use for audio generation
     * @param int $timeout Timeout in seconds
     * @return \Crustum\Ai\Responses\AudioResponse
     */
    public function audio(
        string $text,
        string $voice = 'default-female',
        ?string $instructions = null,
        ?string $model = null,
        int $timeout = 30,
    ): AudioResponse;

    /**
     * Get the provider's audio gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\AudioGateway
     */
    public function audioGateway(): AudioGateway;

    /**
     * Set the provider's audio gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\AudioGateway $gateway Audio gateway
     * @return $this
     */
    public function useAudioGateway(AudioGateway $gateway);

    /**
     * Get the name of the default audio (TTS) model.
     *
     * @return string
     */
    public function defaultAudioModel(): string;
}
