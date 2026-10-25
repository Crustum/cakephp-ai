<?php
declare(strict_types=1);

use Crustum\Ai\AiPlugin;
use Crustum\Ai\Manifest\AiManifest;
use Crustum\PluginManifest\Manifest\ManifestResolver;

it('emits migrations, config, bootstrap and star operations', function (): void {
    $tags = array_column(AiManifest::manifest(), 'tag');

    expect($tags)->toContain('migrations')
        ->and($tags)->toContain('config')
        ->and($tags)->toContain('bootstrap')
        ->and($tags)->toContain('star-repo');
});

it('is discoverable through the resolver', function (): void {
    expect(ManifestResolver::providerClass(AiPlugin::class))->toBe(AiManifest::class)
        ->and(ManifestResolver::manifestFor(AiPlugin::class))->toBe(AiManifest::manifest());
});

it('leaves the plugin class without a manifest contract', function (): void {
    expect(method_exists(AiPlugin::class, 'manifest'))->toBeFalse();
});
