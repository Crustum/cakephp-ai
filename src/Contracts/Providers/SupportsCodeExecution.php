<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Providers\Tools\CodeExecution;

/**
 * Supports Code Execution Interface
 *
 * Contract for providers that support hosted code execution tools.
 */
interface SupportsCodeExecution
{
    /**
     * Get the code execution tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\CodeExecution $codeExecution Code execution tool
     * @return array<string, mixed>
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array;
}
