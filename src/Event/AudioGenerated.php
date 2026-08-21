<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\AudioPrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\AudioResponse;

/**
 * Dispatched after audio is generated.
 */
class AudioGenerated extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param \Crustum\Ai\Prompts\AudioPrompt $prompt Audio prompt
     * @param \Crustum\Ai\Responses\AudioResponse $response Audio response
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public AudioPrompt $prompt,
        public AudioResponse $response,
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
