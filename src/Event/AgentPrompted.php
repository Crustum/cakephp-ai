<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\StreamedAgentResponse;

/**
 * Dispatched after an agent prompt receives a response.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Prompts\AgentPrompt>
 */
class AgentPrompted extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt
     * @param \Crustum\Ai\Responses\StreamedAgentResponse|\Crustum\Ai\Responses\AgentResponse $response Agent response
     */
    public function __construct(
        public string $invocationId,
        public AgentPrompt $prompt,
        public StreamedAgentResponse|AgentResponse $response,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'prompt' => $prompt,
            'response' => $response,
        ], $prompt);
    }
}
