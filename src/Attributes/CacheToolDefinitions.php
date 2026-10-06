<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Cache Tool Definitions Attribute
 *
 * Marks an agent's tool definitions for prompt caching.
 * Anthropic retains a cached prefix for five minutes by default and for an hour
 * when the breakpoint carries a ttl.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class CacheToolDefinitions
{
    /**
     * Constructor.
     *
     * @param string|null $ttl Cache TTL ('5m' or '1h')
     */
    public function __construct(public ?string $ttl = null)
    {
    }
}
