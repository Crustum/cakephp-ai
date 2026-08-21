<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use Crustum\Ai\Contracts\Files\HasName;
use Crustum\Ai\Contracts\Files\TranscribableAudio;

/**
 * Resolves audio filenames for multipart transcription uploads.
 */
trait ResolvesAudioFilenamesTrait
{
    /**
     * Determine the appropriate filename for the audio file based on its MIME type.
     *
     * @param \Crustum\Ai\Contracts\Files\TranscribableAudio $audio Audio to transcribe
     * @return string
     */
    protected function audioFilename(TranscribableAudio $audio): string
    {
        if ($audio instanceof HasName && $audio->name()) {
            return $audio->name();
        }

        $extension = match ($audio->mimeType()) {
            'audio/webm' => 'webm',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mpga' => 'mpga',
            default => 'mp3',
        };

        return 'audio.' . $extension;
    }
}
