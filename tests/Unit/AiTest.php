<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Unit;

use Cake\Core\Container;
use Crustum\Ai\Ai;
use Crustum\Ai\AiManager;
use Crustum\Ai\AiPlugin;
use Crustum\Ai\AnonymousAgent;
use Crustum\Ai\Registry\ProviderRegistry;

beforeEach(function (): void {
    $container = new Container();
    (new AiPlugin(['path' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR]))->services($container);
    Ai::setContainer($container);
});

test('manager resolves AiManager from the container', function (): void {
    $manager = Ai::manager();

    expect($manager)->toBeInstanceOf(AiManager::class)
        ->and(Ai::getManager())->toBe($manager)
        ->and(Ai::container()->get(AiManager::class))->toBe($manager);
});

test('setManager overrides the container instance', function (): void {
    $customManager = new AiManager(new ProviderRegistry());
    Ai::setManager($customManager);

    expect(Ai::getManager())->toBe($customManager);
});

test('setContainer clears the manager override', function (): void {
    Ai::setManager(new AiManager(new ProviderRegistry()));

    $container = new Container();
    (new AiPlugin(['path' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR]))->services($container);
    Ai::setContainer($container);

    expect(Ai::manager())->toBe($container->get(AiManager::class));
});

test('manager methods are reachable via Ai::manager()', function (): void {
    expect(Ai::manager()->getDefaultProvider())->toBe('openrouter');

    Ai::manager()->setDefaultProvider('custom');

    expect(Ai::manager()->getDefaultProvider())->toBe('custom');
});

test('clearInstances works via manager', function (): void {
    Ai::manager()->clearInstances();

    expect(true)->toBeTrue();
});

test('Ai::make resolves named constructor arguments via ReflectionContainer', function (): void {
    $agent = Ai::make(AnonymousAgent::class, [
        'instructions' => 'Be helpful',
        'messages' => [],
        'tools' => [],
    ]);

    expect($agent)->toBeInstanceOf(AnonymousAgent::class)
        ->and($agent->instructions())->toBe('Be helpful');
});
