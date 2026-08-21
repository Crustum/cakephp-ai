<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Cake\Core\ContainerInterface;
use League\Container\ReflectionContainer;
use RuntimeException;

/**
 * Thin static accessor for the application container and AiManager.
 *
 * CakePHP has no Container::getInstance(); bootstrap must call setContainer().
 * Prefer injecting AiManager in application code.
 *
 * Agent::make() uses League ReflectionContainer (Cake 5.3 DI stack) for
 * auto-wiring and named constructor args — not a hand-rolled resolver.
 *
 * @see \Crustum\Ai\AiManager
 */
class Ai
{
    /**
     * Application DI container.
     */
    protected static ?ContainerInterface $container = null;

    /**
     * Auto-wiring delegate used for Agent::make() / named arguments.
     */
    protected static ?ReflectionContainer $reflectionContainer = null;

    /**
     * Test override for AiManager; takes precedence over the container.
     */
    protected static ?AiManager $managerOverride = null;

    /**
     * Bind the application container and enable auto-wiring for make().
     *
     * Does not attach ReflectionContainer as a container delegate — Cake's
     * Application already delegates one; stacking another causes infinite resolve.
     *
     * @param \Cake\Core\ContainerInterface $container Container instance
     * @return void
     */
    public static function setContainer(ContainerInterface $container): void
    {
        static::$container = $container;
        static::$managerOverride = null;
        static::$reflectionContainer = new ReflectionContainer(false);
    }

    /**
     * Get the bound application container.
     *
     * @return \Cake\Core\ContainerInterface
     * @throws \RuntimeException When setContainer() was not called
     */
    public static function container(): ContainerInterface
    {
        if (!static::$container instanceof ContainerInterface) {
            throw new RuntimeException(
                'Ai container is not set. Call Ai::setContainer() from Application or test bootstrap.',
            );
        }

        return static::$container;
    }

    /**
     * Whether a container has been bound.
     *
     * @return bool
     */
    public static function hasContainer(): bool
    {
        return static::$container instanceof ContainerInterface;
    }

    /**
     * Resolve a class via the container.
     *
     * Empty $parameters → auto-wire. Named $parameters → constructor overrides.
     *
     * @param class-string $class Class name
     * @param array<string, mixed> $parameters Named constructor arguments
     * @return object
     */
    public static function make(string $class, array $parameters = []): object
    {
        if (!static::$reflectionContainer instanceof ReflectionContainer) {
            throw new RuntimeException(
                'Ai container is not set. Call Ai::setContainer() from Application or test bootstrap.',
            );
        }

        /** @var object $instance */
        $instance = static::$reflectionContainer->get($class, $parameters);

        return $instance;
    }

    /**
     * Get the shared AiManager (override, or container).
     *
     * @return \Crustum\Ai\AiManager
     */
    public static function manager(): AiManager
    {
        if (static::$managerOverride instanceof AiManager) {
            return static::$managerOverride;
        }

        return static::container()->get(AiManager::class);
    }

    /**
     * Get the AiManager instance.
     *
     * @return \Crustum\Ai\AiManager
     */
    public static function getManager(): AiManager
    {
        return static::manager();
    }

    /**
     * Override the AiManager (tests).
     *
     * @param \Crustum\Ai\AiManager $manager Manager instance
     * @return void
     */
    public static function setManager(AiManager $manager): void
    {
        static::$managerOverride = $manager;
    }
}
