<?php
declare(strict_types=1);

namespace Crustum\Ai\Utility;

/**
 * Identifier generation utilities.
 */
class Identifier
{
    /**
     * Generate a new ULID (Universally Unique Lexicographically Sortable Identifier).
     *
     * @return string
     */
    public static function ulid(): string
    {
        $encoding = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = (int)(microtime(true) * 1000);
        $timePart = '';

        for ($index = 9; $index >= 0; $index--) {
            $timePart = $encoding[$time % 32] . $timePart;
            $time = intdiv($time, 32);
        }

        $randomPart = '';

        for ($index = 0; $index < 16; $index++) {
            $randomPart .= $encoding[random_int(0, 31)];
        }

        return strtolower($timePart . $randomPart);
    }
}
