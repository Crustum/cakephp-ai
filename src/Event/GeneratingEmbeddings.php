<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\EmbeddingsPrompt;
use Crustum\Ai\Providers\Provider;

/**
 * Dispatched before embeddings are generated.
 */
class GeneratingEmbeddings extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param \Crustum\Ai\Prompts\EmbeddingsPrompt $prompt Embeddings prompt
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public EmbeddingsPrompt $prompt,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
        ]);
    }
}
