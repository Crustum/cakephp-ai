<?php
declare(strict_types=1);

namespace Crustum\Ai\Tools;

use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Providers\Tools\ProviderTool;

/**
 * Tool Name Resolver
 *
 * Resolves the name of a tool instance.
 */
class ToolNameResolver
{
    /**
     * Resolve the name of a tool.
     *
     * @param \Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool $tool Tool instance
     * @return string Tool name
     */
    public static function resolve(Tool|ProviderTool $tool): string
    {
        if (is_callable([$tool, 'name'])) {
            return $tool->name();
        }

        $className = $tool::class;
        $parts = explode('\\', $className);

        return end($parts);
    }
}
