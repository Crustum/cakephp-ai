<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Providers\OpenAiProvider;

test('can get an openai provider instance', function (): void {
    expect(Ai::manager()->textProvider('openai'))->toBeInstanceOf(OpenAiProvider::class);
});

test('provider type is ensured', function (): void {
    Ai::manager()->audioProvider('anthropic');
})->throws(LogicException::class);

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
