<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Unit;

use Cake\Core\Configure;
use Crustum\Ai\AiManager;
use Crustum\Ai\Registry\ProviderRegistry;

beforeEach(function (): void {
    $this->registry = new ProviderRegistry();
    $this->manager = new AiManager($this->registry);
});

afterEach(function (): void {
    $this->manager->clearInstances();
});

test('gets default provider from config', function (): void {
    expect($this->manager->getDefaultProvider())->toBe('openrouter');
});

test('sets default provider', function (): void {
    $this->manager->setDefaultProvider('custom');

    expect($this->manager->getDefaultProvider())->toBe('custom');
});

test('clearInstances clears registry', function (): void {
    $this->manager->clearInstances();

    expect($this->registry->has('openrouter'))->toBeFalse();
});

test('provider returns default when name is null', function (): void {
    Configure::write('Ai.defaultProvider', 'openrouter');

    expect($this->manager->getDefaultProvider())->toBe('openrouter');
});
