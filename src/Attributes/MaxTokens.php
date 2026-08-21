<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Attribute to specify maximum tokens.
 *
 * Controls the maximum number of tokens in the generated response.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class MaxTokens
{
    /**
     * Constructor.
     *
     * @param int $value Maximum number of tokens
     */
    public function __construct(public int $value)
    {
    }
}
