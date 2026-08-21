<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Unit;

use Cake\Core\Configure;
use Cake\Core\Plugin;
use Crustum\Ai\AiPlugin;
use Crustum\Ai\Providers\OpenRouterProvider;

/**
 * AiPlugin Test
 */
test('plugin loads correctly', function (): void {
    $plugin = new AiPlugin();

    expect($plugin->getName())->toBe('Ai');
});

test('configuration loads', function (): void {
    $plugin = Plugin::getCollection()->get('Ai');

    expect($plugin)->toBeInstanceOf(AiPlugin::class);
    expect(Configure::check('Ai'))->toBeTrue();
    expect(Configure::read('Ai.defaultProvider'))->toBe('openrouter');
});

test('configuration has providers', function (): void {
    expect(Configure::check('Ai.providers'))->toBeTrue();
    expect(Configure::read('Ai.providers'))->toBeArray();
    expect(Configure::read('Ai.providers.openrouter'))->toBeArray();
});

test('configuration has required keys', function (): void {
    expect(Configure::check('Ai.defaultProvider'))->toBeTrue();
    expect(Configure::check('Ai.providers'))->toBeTrue();
    expect(Configure::check('Ai.broadcasting'))->toBeTrue();
    expect(Configure::check('Ai.queue'))->toBeTrue();
    expect(Configure::check('Ai.storage'))->toBeTrue();
});

test('provider configuration has className', function (): void {
    $openrouter = Configure::read('Ai.providers.openrouter');

    expect($openrouter)->toHaveKey('className');
    expect($openrouter['className'])->toBe(OpenRouterProvider::class);
});
