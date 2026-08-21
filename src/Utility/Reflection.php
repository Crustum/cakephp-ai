<?php
declare(strict_types=1);

namespace Crustum\Ai\Utility;

/**
 * Reflection utilities for class and trait introspection.
 */
class Reflection
{
    /**
     * Get the class basename of the given object or class name.
     *
     * @param object|string $class Object or class name
     * @return string
     */
    public static function classBasename(object|string $class): string
    {
        $class = is_object($class) ? $class::class : $class;

        return basename(str_replace('\\', '/', $class));
    }

    /**
     * Get all traits used by a class, its parent classes, and trait hierarchies.
     *
     * @param object|string $class Class name or object
     * @return array<int|string, string>
     */
    public static function classUsesRecursive(object|string $class): array
    {
        if (is_object($class)) {
            $class = $class::class;
        }

        $results = [];

        foreach (array_reverse(class_parents($class) ?: []) + [$class => $class] as $parent) {
            $results += static::traitUsesRecursive($parent);
        }

        return array_unique($results);
    }

    /**
     * Get all traits used by a trait and its parent traits.
     *
     * @param string $trait Trait name
     * @return array<int|string, string>
     */
    public static function traitUsesRecursive(string $trait): array
    {
        $traits = class_uses($trait) ?: [];

        foreach ($traits as $usedTrait) {
            $traits += static::traitUsesRecursive($usedTrait);
        }

        return $traits;
    }
}
