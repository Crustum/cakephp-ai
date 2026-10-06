<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Utility\Text;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Trait\StorableTrait;
use Stringable;

/**
 * Audio Response
 *
 * Represents an audio generation response.
 */
class AudioResponse implements Stringable
{
    use StorableTrait;

    /**
     * MIME type of the audio
     */
    public ?string $mime = null;

    /**
     * Constructor
     *
     * @param string $audio The Base64 representation of the audio
     * @param \Crustum\Ai\Responses\Data\Usage $usage Token usage for the request
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata about the response
     * @param string|null $mimeType The MIME type of the audio
     */
    public function __construct(
        public string $audio,
        public Usage $usage,
        public Meta $meta,
        ?string $mimeType = null,
    ) {
        $this->mime = $mimeType;
    }

    /**
     * Get a default filename for the file.
     *
     * @return string
     */
    protected function randomStorageName(): string
    {
        $this->randomStorageName ??= Text::uuid() . match ($this->mime) {
            'audio/wav', 'audio/x-wav' => '.wav',
            'audio/opus' => '.opus',
            'audio/pcm' => '.pcm',
            'audio/ulaw' => '.ulaw',
            'audio/alaw' => '.alaw',
            default => '.mp3',
        };

        return $this->randomStorageName;
    }

    /**
     * Get the raw representation of the audio.
     *
     * @return string
     */
    public function content(): string
    {
        return base64_decode($this->audio);
    }

    /**
     * Get the MIME type for the audio.
     */
    public function mimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the raw string content of the audio.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
