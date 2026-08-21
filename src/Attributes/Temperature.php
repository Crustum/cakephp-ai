<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Attribute to specify sampling temperature.
 *
 * Controls randomness in the model's output. Higher values (e.g., 1.0)
 * make output more random, lower values (e.g., 0.1) more deterministic.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Temperature
{
    /**
     * Constructor.
     *
     * @param float $value Temperature value (typically 0.0 to 2.0)
     */
    public function __construct(public float $value)
    {
    }
}
