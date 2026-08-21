<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support;

use Cake\Utility\Text;

/**
 * Test helper for string utilities used by ported feature tests.
 */
class Str
{
    /**
     * Wrap a string value for fluent test helpers.
     *
     * @param string $value String value
     * @return \Crustum\Ai\Test\Support\StringableHelper
     */
    public static function of(string $value): StringableHelper
    {
        return new StringableHelper($value);
    }

    /**
     * Generate a UUID string for tests.
     *
     * @return string
     */
    public static function uuid7(): string
    {
        return Text::uuid();
    }
}
