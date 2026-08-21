<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\AgentPrompt;

/**
 * Dispatched before an agent prompt is sent to the provider.
 */
class PromptingAgent extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     */
    public function __construct(
        public string $invocationId,
        public AgentPrompt $prompt,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'prompt' => $prompt,
        ]);
    }
}
