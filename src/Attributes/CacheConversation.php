<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Marks the last conversation message as an Anthropic prompt-cache breakpoint.
 *
 * When this attribute is present on an agent, the gateway stamps an
 * `cache_control` breakpoint on the final content block of the most recent
 * message so that the model re-reads the cached conversation history on each
 * step instead of re-paying input tokens for it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class CacheConversation
{
    /**
     * @param string|null $ttl Prompt-cache time-to-live, or null for the provider default.
     */
    public function __construct(public ?string $ttl = null)
    {
    }
}
