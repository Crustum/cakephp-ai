<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\ClassificationPrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\ClassificationResponse;

/**
 * Dispatched after questions are classified.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Providers\Provider>
 */
class Classified extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param \Crustum\Ai\Prompts\ClassificationPrompt $prompt Classification prompt
     * @param \Crustum\Ai\Responses\ClassificationResponse $response Classification response
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ClassificationPrompt $prompt,
        public ClassificationResponse $response,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
            'response' => $response,
        ], $provider);
    }
}
