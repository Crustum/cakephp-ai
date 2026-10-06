<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Providers\AnthropicProvider;
use Crustum\Ai\Providers\OpenAiCompatibleProvider;
use Crustum\Ai\Providers\OpenAiProvider;

test('can get an openai provider instance', function (): void {
    expect(Ai::manager()->textProvider('openai'))->toBeInstanceOf(OpenAiProvider::class);
});

test('provider type is ensured', function (): void {
    Ai::manager()->audioProvider('anthropic');
})->throws(LogicException::class);

test('a configured provider casts to its driver', function (): void {
    Configure::write('Ai.providers.cloudflare', [
        'className' => OpenAiCompatibleProvider::class,
        'driver' => 'openai-compatible',
        'url' => 'https://example.com/v1',
    ]);

    expect((string)Ai::manager()->textProvider('cloudflare'))->toBe('openai-compatible');
});

test('an on-demand provider cannot replace a configured provider', function (): void {
    Ai::manager()->build(['name' => 'anthropic', 'driver' => 'anthropic', 'key' => 'tenant-key']);
})->throws(InvalidArgumentException::class, 'The provider name [anthropic] is already taken.');

test('an on-demand provider cannot take a built-in provider name', function (): void {
    Configure::write('Ai.providers', []);

    Ai::manager()->build(['name' => 'openai', 'driver' => 'anthropic', 'key' => 'tenant-key']);
})->throws(InvalidArgumentException::class, 'The provider name [openai] is already taken.');

test('rebuilding a named on-demand provider uses the new configuration', function (): void {
    Ai::manager()->build(['name' => 'tenant', 'driver' => 'anthropic', 'key' => 'old-key']);

    expect(Ai::manager()->build(['name' => 'tenant', 'driver' => 'anthropic', 'key' => 'new-key'])->providerCredentials())
        ->toBe(['key' => 'new-key']);
});

test('an on-demand provider keeps its internal flag out of its additional configuration', function (): void {
    expect(Ai::manager()->build(['driver' => 'anthropic', 'key' => 'tenant-key', 'url' => 'https://tenant.example.com/v1'])->additionalConfiguration())
        ->toBe(['url' => 'https://tenant.example.com/v1', 'className' => AnthropicProvider::class]);
});

test('flushing state forgets on-demand providers but keeps configured providers', function (): void {
    $configured = Ai::manager()->textProvider('anthropic');
    $onDemand = Ai::manager()->build(['driver' => 'anthropic', 'key' => 'tenant-key']);

    Ai::manager()->flushState();

    expect(Ai::manager()->textProvider('anthropic'))->toBe($configured)
        ->and(fn(): TextProvider => Ai::manager()->textProvider($onDemand->name()))
        ->toThrow(InvalidArgumentException::class, 'was not built in this process');
});

test('driver extensions survive between queue jobs', function (): void {
    Configure::write('Ai.providers.custom', [
        'className' => OpenAiProvider::class,
        'key' => 'test-key',
        'models' => ['text' => ['default' => 'custom-model']],
    ]);

    $resolveOnFreshScope = function (): TextProvider {
        Ai::manager()->clearInstances();

        return Ai::manager()->textProvider('custom');
    };

    expect($resolveOnFreshScope())->toBeInstanceOf(OpenAiProvider::class);
    expect($resolveOnFreshScope())->toBeInstanceOf(OpenAiProvider::class);
});
