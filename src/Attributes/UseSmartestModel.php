<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Attribute to use the smartest model.
 *
 * Instructs the system to select the most capable/intelligent model
 * available for the provider.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UseSmartestModel
{
}
