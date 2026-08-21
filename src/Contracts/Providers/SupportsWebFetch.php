<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Providers\Tools\WebFetch;

/**
 * Supports Web Fetch Interface
 *
 * Marker interface for providers that support web fetch tools.
 */
interface SupportsWebFetch
{
    /**
     * Get the web fetch tool options for the provider.
     *
     * @param \Crustum\Ai\Providers\Tools\WebFetch $fetch Web fetch tool
     * @return array<string, mixed>
     */
    public function webFetchToolOptions(WebFetch $fetch): array;
}
