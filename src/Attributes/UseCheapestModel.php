<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;

/**
 * Attribute to use the cheapest model.
 *
 * Instructs the system to select the most cost-effective model
 * available for the provider.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UseCheapestModel
{
}
