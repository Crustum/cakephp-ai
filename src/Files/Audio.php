<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

/**
 * Audio file.
 *
 * Abstract base class for audio files (MP3, WAV, OGG, etc.).
 */
abstract class Audio extends File
{
    /**
     * Get the raw representation of the file.
     *
     * @return string
     */
    abstract public function content(): string;

    /**
     * Create a new audio from Base64 data.
     *
     * @param string $base64 Base64-encoded content
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\Base64Audio
     */
    public static function fromBase64(string $base64, ?string $mimeType = null): Base64Audio
    {
        return new Base64Audio($base64, $mimeType);
    }

    /**
     * Create a new audio using the audio at the given path.
     *
     * @param string $path Local file path
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\LocalAudio
     */
    public static function fromPath(string $path, ?string $mimeType = null): LocalAudio
    {
        return new LocalAudio($path, $mimeType);
    }

    /**
     * Create a new remote audio using the audio at the given URL.
     *
     * @param string $url Audio URL
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\RemoteAudio
     */
    public static function fromUrl(string $url, ?string $mimeType = null): RemoteAudio
    {
        return new RemoteAudio($url, $mimeType);
    }

    /**
     * Create a new stored audio using the audio at the given path on the given filesystem.
     *
     * @param string $path Storage path
     * @param string|null $filesystem Named filesystem operator
     * @return \Crustum\Ai\Files\StoredAudio
     */
    public static function fromStorage(string $path, ?string $filesystem = null): StoredAudio
    {
        return new StoredAudio($path, $filesystem);
    }
}
