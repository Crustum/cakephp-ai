<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Providers\Tools\WebSearch;

/**
 * Supports Web Search Interface
 *
 * Marker interface for providers that support web search tools.
 */
interface SupportsWebSearch
{
    /**
     * Get the web search tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $search Web search tool
     * @return array<string, mixed>
     */
    public function webSearchToolOptions(WebSearch $search): array;
}
