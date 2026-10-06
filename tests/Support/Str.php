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
     * Generate a UUID string for tests.
     *
     * @return string
     */
    public static function uuid7(): string
    {
        return Text::uuid();
    }
}
