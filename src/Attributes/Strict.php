<?php
declare(strict_types=1);

namespace Crustum\Ai\Attributes;

use Attribute;
use ReflectionClass;

/**
 * Attribute to enable strict mode.
 *
 * Enables strict JSON schema validation for structured outputs.
 * When applied, the model must adhere exactly to the specified schema.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Strict
{
    /**
     * Determine if the Strict attribute is applied to the target.
     *
     * @param object|null $target The target object to check
     * @return bool
     */
    public static function isAppliedTo(?object $target): bool
    {
        return $target !== null
            && (new ReflectionClass($target))->getAttributes(self::class) !== [];
    }
}
