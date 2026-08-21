<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\RerankingPrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\RerankingResponse;

/**
 * Dispatched after documents are reranked.
 */
class Reranked extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param \Crustum\Ai\Prompts\RerankingPrompt $prompt Reranking prompt
     * @param \Crustum\Ai\Responses\RerankingResponse $response Reranking response
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public RerankingPrompt $prompt,
        public RerankingResponse $response,
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
