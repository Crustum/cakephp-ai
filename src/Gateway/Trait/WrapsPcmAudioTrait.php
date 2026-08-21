<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

/**
 * Wraps PCM Audio Trait
 *
 * Wraps raw PCM audio data in a WAV container.
 */
trait WrapsPcmAudioTrait
{
    /**
     * Wrap raw PCM audio bytes in a WAV file header.
     *
     * @param string $pcm Raw PCM audio data
     * @param int $sampleRate Sample rate in Hz
     * @param int $channels Number of audio channels
     * @param int $bitsPerSample Bits per sample
     * @return string
     */
    protected function pcmToWav(string $pcm, int $sampleRate = 24000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $dataSize = strlen($pcm);
        $byteRate = intdiv($sampleRate * $channels * $bitsPerSample, 8);
        $blockAlign = intdiv($channels * $bitsPerSample, 8);

        return 'RIFF'
            . pack('V', 36 + $dataSize)
            . 'WAVE'
            . 'fmt '
            . pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
            . 'data'
            . pack('V', $dataSize)
            . $pcm;
    }
}
