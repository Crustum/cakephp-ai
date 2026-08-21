<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Providers\Tools\FileSearch;

/**
 * Supports File Search Interface
 *
 * Marker interface for providers that support file search tools.
 */
interface SupportsFileSearch
{
    /**
     * Get the file search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\FileSearch $search File search tool
     * @return array<string, mixed>
     */
    public function fileSearchToolOptions(FileSearch $search): array;
}
