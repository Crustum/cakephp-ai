<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\TranscriptionPrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\TranscriptionResponse;

/**
 * Dispatched after a transcription is generated.
 */
class TranscriptionGenerated extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param \Crustum\Ai\Prompts\TranscriptionPrompt $prompt Transcription prompt
     * @param \Crustum\Ai\Responses\TranscriptionResponse $response Transcription response
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public TranscriptionPrompt $prompt,
        public TranscriptionResponse $response,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
            'response' => $response,
        ]);
    }
}
