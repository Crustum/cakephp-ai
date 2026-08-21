<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Tool;

/**
 * Dispatched after a tool is invoked.
 */
class ToolInvoked extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param string $toolInvocationId Tool invocation identifier
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @param array<string, mixed> $arguments Tool arguments
     * @param mixed $result Tool result
     * @param float $time Wall time spent in the tool's handler, in milliseconds
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
        public $result,
        public float $time,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'toolInvocationId' => $toolInvocationId,
            'agent' => $agent,
            'tool' => $tool,
            'arguments' => $arguments,
            'result' => $result,
            'time' => $time,
        ]);
    }
}
