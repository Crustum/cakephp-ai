<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Attribute to specify maximum agent steps.
 *
 * Controls the maximum number of steps an agent can take
 * before stopping, preventing infinite loops.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class MaxSteps
{
    /**
     * Constructor.
     *
     * @param int $value Maximum number of steps
     */
    public function __construct(public int $value)
    {
    }
}
