<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\TestCase\Registry;

use Cake\Core\Configure;
use Crustum\Ai\Registry\ProviderRegistry;
use InvalidArgumentException;
use RuntimeException;

beforeEach(function (): void {
    $this->registry = new ProviderRegistry();
});

afterEach(function (): void {
    $this->registry->clear();
});

test('throws exception when provider not configured', function (): void {
    expect(fn() => $this->registry->load('nonexistent'))
        ->toThrow(InvalidArgumentException::class, "Provider 'nonexistent' is not configured.");
});

test('throws exception when className missing', function (): void {
    Configure::write('Ai.providers.test', ['apiKey' => 'test']);

    expect(fn() => $this->registry->load('test'))
        ->toThrow(InvalidArgumentException::class, "Provider 'test' is missing 'className'");
});

test('throws exception when class not found', function (): void {
    Configure::write('Ai.providers.test', [
        'className' => 'NonExistent\Provider\Class',
    ]);

    expect(fn() => $this->registry->load('test'))
        ->toThrow(RuntimeException::class, "Provider class 'NonExistent\Provider\Class' not found.");
});

test('has returns false for unloaded provider', function (): void {
    expect($this->registry->has('openrouter'))->toBeFalse();
});

test('get returns null for unloaded provider', function (): void {
    expect($this->registry->get('openrouter'))->toBeNull();
});

test('unload removes provider instance', function (): void {
    $this->registry->clear();

    expect($this->registry->has('test'))->toBeFalse();
});

test('clear removes all providers', function (): void {
    $this->registry->clear();

    expect($this->registry->has('openrouter'))->toBeFalse();
});
