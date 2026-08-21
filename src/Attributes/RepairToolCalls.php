<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;
use ReflectionClass;

/**
 * Marks an agent so unknown local tool calls are repaired instead of failing the run.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RepairToolCalls
{
    /**
     * Determine if the attribute is applied to the given target.
     *
     * @param object|null $target Target object
     * @return bool
     */
    public static function isAppliedTo(?object $target): bool
    {
        return $target !== null
            && (new ReflectionClass($target))->getAttributes(self::class) !== [];
    }
}
