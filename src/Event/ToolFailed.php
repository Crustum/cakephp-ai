<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Tool;
use Throwable;

/**
 * Dispatched after a tool's handler throws.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Contracts\Agent>
 */
class ToolFailed extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Run invocation identifier
     * @param string $toolInvocationId Tool invocation identifier
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @param array<string, mixed> $arguments Tool arguments
     * @param \Throwable $exception The failure
     * @param float $time Wall time spent in the tool's handler before it threw, in milliseconds
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
        public Throwable $exception,
        public float $time,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'toolInvocationId' => $toolInvocationId,
            'agent' => $agent,
            'tool' => $tool,
            'arguments' => $arguments,
            'exception' => $exception,
            'time' => $time,
        ], $agent);
    }
}
