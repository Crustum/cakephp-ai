<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;
use Crustum\Ai\Enums\Lab;

/**
 * Attribute to specify AI provider.
 *
 * Specifies which AI provider(s) to use for the agent or operation.
 * Can be a single provider, array of providers, or Lab enum.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Provider
{
    /**
     * Constructor.
     *
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string $value Provider specification
     */
    public function __construct(public Lab|array|string $value)
    {
    }
}
