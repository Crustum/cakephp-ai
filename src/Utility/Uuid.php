<?php
declare(strict_types=1);

namespace Crustum\Ai\Utility;

/**
 * Minimal time-sortable UUID v7 generator.
 *
 * Mirrors CakePHP 6 `Text::uuid()` behavior so ids sort chronologically.
 */
final class Uuid
{
    /**
     * Generate a time-sortable UUID v7 string.
     *
     * @return string
     */
    public static function v7(): string
    {
        $value = random_bytes(16);

        $timestamp = intval(microtime(true) * 1000);

        $value[0] = chr(($timestamp >> 40) & 0xFF);
        $value[1] = chr(($timestamp >> 32) & 0xFF);
        $value[2] = chr(($timestamp >> 24) & 0xFF);
        $value[3] = chr(($timestamp >> 16) & 0xFF);
        $value[4] = chr(($timestamp >> 8) & 0xFF);
        $value[5] = chr($timestamp & 0xFF);

        $value[6] = chr((ord($value[6]) & 0x0F) | 0x70);
        $value[8] = chr((ord($value[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($value), 4));
    }
}
