<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\AudioPrompt;
use Crustum\Ai\Providers\Provider;

/**
 * Dispatched before audio is generated.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Providers\Provider>
 */
class GeneratingAudio extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param \Crustum\Ai\Prompts\AudioPrompt $prompt Audio prompt
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public AudioPrompt $prompt,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
        ], $provider);
    }
}
