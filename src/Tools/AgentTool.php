<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\CanActAsTool;
use Crustum\Ai\Contracts\Tool;
use Crustum\JsonSchema\Contracts\JsonSchema;
use ReflectionClass;
use Stringable;
use Throwable;

/**
 * Wraps an agent as a callable tool.
 */
class AgentTool implements Tool
{
    /**
     * @param \Crustum\Ai\Contracts\Agent $agent Underlying agent
     */
    public function __construct(protected Agent $agent)
    {
    }

    /**
     * Get the name of the tool.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->agent instanceof CanActAsTool
            ? $this->agent->name()
            : (new ReflectionClass($this->agent))->getShortName();
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return $this->agent instanceof CanActAsTool
            ? $this->agent->description()
            : sprintf(
                'Delegates a task to the %s sub-agent and returns its response. Pass a clear, self-contained task description as the sub-agent runs in isolation and has no access to the parent conversation history.',
                $this->name(),
            );
    }

    /**
     * Execute the tool.
     *
     * @param \Crustum\Ai\Tools\Request $request Tool request
     * @return string
     */
    public function handle(Request $request): string
    {
        try {
            return $this->agent->prompt((string)$request['task'])->text;
        } catch (Throwable $throwable) {
            return 'Agent failed: ' . $throwable->getMessage();
        }
    }

    /**
     * Get the tool's schema definition.
     *
     * @param \Crustum\JsonSchema\Contracts\JsonSchema $schema Schema builder
     * @return array<string, \Crustum\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task to delegate to this agent.')->required(),
        ];
    }

    /**
     * Get the underlying agent instance.
     *
     * @return \Crustum\Ai\Contracts\Agent
     */
    public function agent(): Agent
    {
        return $this->agent;
    }
}
