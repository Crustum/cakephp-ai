<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Attribute to specify top-p (nucleus) sampling.
 *
 * Controls diversity via nucleus sampling. Consider only tokens whose
 * cumulative probability is above this threshold.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class TopP
{
    /**
     * Constructor.
     *
     * @param float $value Top-p value (typically 0.0 to 1.0)
     */
    public function __construct(public float $value)
    {
    }
}
