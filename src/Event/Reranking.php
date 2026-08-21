<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\RerankingPrompt;
use Crustum\Ai\Providers\Provider;

/**
 * Dispatched before documents are reranked.
 */
class Reranking extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param \Crustum\Ai\Prompts\RerankingPrompt $prompt Reranking prompt
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public RerankingPrompt $prompt,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
        ]);
    }
}
