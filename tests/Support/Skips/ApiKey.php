<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Skips;

/**
 * Test skip helper for API keys.
 */
class ApiKey
{
    /**
     * Skip the current test when any given API key environment variable is missing.
     *
     * @param string ...$envVars Environment variable names.
     * @return void
     */
    public static function required(string ...$envVars): void
    {
        foreach ($envVars as $envVar) {
            $key = getenv($envVar) ?: ($_ENV[$envVar] ?? null);

            if (empty($key)) {
                test()->markTestSkipped($envVar . ' is not set.');
            }
        }
    }
}
