<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Attribute to specify request timeout.
 *
 * Controls the maximum time (in seconds) to wait for a response.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Timeout
{
    /**
     * Constructor.
     *
     * @param int $value Timeout in seconds
     */
    public function __construct(public int $value)
    {
    }
}
