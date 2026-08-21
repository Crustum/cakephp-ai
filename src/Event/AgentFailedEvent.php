<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\AgentPrompt;
use Throwable;

/**
 * Dispatched once a run terminates without a response.
 */
class AgentFailedEvent extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Run invocation identifier
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param \Throwable $exception The terminal failure
     */
    public function __construct(
        public string $invocationId,
        public AgentPrompt $prompt,
        public Throwable $exception,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'prompt' => $prompt,
            'exception' => $exception,
        ]);
    }
}
