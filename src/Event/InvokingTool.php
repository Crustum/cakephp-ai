<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Tool;

/**
 * Dispatched before a tool is invoked.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Contracts\Agent>
 */
class InvokingTool extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param string $toolInvocationId Tool invocation identifier
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @param array<string, mixed> $arguments Tool arguments
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'toolInvocationId' => $toolInvocationId,
            'agent' => $agent,
            'tool' => $tool,
            'arguments' => $arguments,
        ], $agent);
    }
}
