<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Tool;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Event\ToolInvoked;
use Crustum\Ai\Tools\ToolNameResolver;

/**
 * Recorded tool invocation for agentic flow assertions.
 */
class RecordedToolInvocation
{
    /**
     * @param string $name Tool name
     * @param array<string, mixed> $arguments Tool arguments
     * @param mixed $result Tool result
     * @param string $invocationId Agent invocation identifier
     * @param string $toolInvocationId Tool invocation identifier
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agentClass Agent class name
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @param float $time Wall time spent in the tool's handler, in milliseconds
     */
    public function __construct(
        public string $name,
        public array $arguments,
        public mixed $result,
        public string $invocationId,
        public string $toolInvocationId,
        public string $agentClass,
        public Tool $tool,
        public float $time = 0.0,
    ) {
    }

    /**
     * Build a recorded invocation from a ToolInvoked event.
     *
     * @param \Crustum\Ai\Event\ToolInvoked $event Tool invoked event
     * @return self
     */
    public static function fromEvent(ToolInvoked $event): self
    {
        return new self(
            ToolNameResolver::resolve($event->tool),
            $event->arguments,
            $event->result,
            $event->invocationId,
            $event->toolInvocationId,
            $event->agent::class,
            $event->tool,
            $event->time,
        );
    }

    /**
     * Summarize the invocation for assertion failure output.
     *
     * @return string
     */
    public function summary(): string
    {
        $argumentKeys = array_keys($this->arguments);

        return sprintf(
            '%s args=[%s] agent=%s',
            $this->name,
            implode(', ', $argumentKeys),
            $this->agentClass,
        );
    }
}
