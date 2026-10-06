<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Cache Instructions Attribute
 *
 * Marks an agent's system instructions for prompt caching.
 * Anthropic retains a cached prefix for five minutes by default and for an hour
 * when the breakpoint carries a ttl.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class CacheInstructions
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
