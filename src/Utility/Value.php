<?php
declare(strict_types=1);

namespace Crustum\Ai\Utility;

use Countable;

/**
 * Value utility methods.
 */
class Value
{
    /**
     * Determine if the given value is filled (not blank).
     *
     * @return bool
     */
    public static function filled(mixed $value): bool
    {
        return !static::blank($value);
    }

    /**
     * Determine if the given value is blank.
     *
     * @return bool
     */
    public static function blank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        if ($value instanceof Countable) {
            return count($value) === 0;
        }

        return false;
    }
}
