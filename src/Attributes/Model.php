<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Attribute to specify AI model.
 *
 * Specifies which AI model to use for the agent or operation.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Model
{
    /**
     * Constructor.
     *
     * @param string $value Model identifier (e.g., "gpt-4", "claude-3-opus")
     */
    public function __construct(public string $value)
    {
    }
}
