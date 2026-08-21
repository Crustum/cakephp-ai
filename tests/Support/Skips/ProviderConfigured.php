<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Support\Skips;

use Cake\Core\Configure;

/**
 * Test skip helper for provider configuration.
 */
class ProviderConfigured
{
    /**
     * Skip the current test when the given store provider is not configured.
     *
     * @param string $provider Provider name.
     * @return void
     */
    public static function store(string $provider): void
    {
        if (!Configure::check('Ai.providers.' . $provider)) {
            test()->markTestSkipped(sprintf("Provider '%s' is not configured.", $provider));
        }
    }
}
