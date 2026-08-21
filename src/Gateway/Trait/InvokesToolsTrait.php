<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Gateway\ParentInvocation;
use Crustum\Ai\Gateway\RunContext;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Tools\Request;
use Crustum\Ai\Tools\ToolNameResolver;
use Throwable;

/**
 * Invokes Tools Trait
 *
 * Executes tools and reports their invocations through the run context.
 */
trait InvokesToolsTrait
{
    use MeasuresDurationTrait;

    /**
     * Execute the given tool with the given arguments.
     *
     * @param \Crustum\Ai\Contracts\Tool $tool Tool to execute
     * @param array<string, mixed> $arguments Tool arguments
     * @param string|null $toolCallId Stable provider tool-call ID
     * @param \Crustum\Ai\Gateway\RunContext|null $context Run context
     * @return string Tool result
     */
    protected function executeTool(Tool $tool, array $arguments, ?string $toolCallId = null, ?RunContext $context = null): string
    {
        $toolInvocationId = strtolower(Text::uuid());

        return ParentInvocation::within($context?->invocationId, $toolInvocationId, function () use ($tool, $arguments, $toolCallId, $toolInvocationId, $context): string {
            $context?->invokingTool($tool, $arguments, $toolInvocationId);

            $startedAt = hrtime(true);

            try {
                $result = $tool->handle(new Request($arguments, $toolCallId, $toolInvocationId));
            } catch (Throwable $throwable) {
                $context?->toolFailed($tool, $arguments, $throwable, $toolInvocationId, $this->elapsedMilliseconds($startedAt));

                throw $throwable;
            }

            $context?->toolInvoked($tool, $arguments, $result, $toolInvocationId, $this->elapsedMilliseconds($startedAt));

            return (string)$result;
        });
    }

    /**
     * Find a tool by its name from the given tools array.
     *
     * @param string $name Tool name
     * @param array<int, \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ToolSearch> $tools Available tools
     */
    protected function findTool(string $name, array $tools): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolSearch) {
                $nested = $this->findTool($name, $tool->tools);

                if ($nested instanceof Tool) {
                    return $nested;
                }

                continue;
            }

            if (ToolNameResolver::resolve($tool) === $name) {
                return $tool;
            }
        }

        return null;
    }
}
