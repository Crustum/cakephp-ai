<?php
declare(strict_types=1);

namespace TestApp;

use Bake\BakePlugin;
use Cake\Core\Plugin;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;
use Crustum\Ai\AiPlugin;
use Override;

/**
 * Test application for Ai plugin tests.
 */
class Application extends BaseApplication
{
    /**
     * @return void
     */
    #[Override]
    public function bootstrap(): void
    {
        if (!Plugin::isLoaded('Bake')) {
            $this->addPlugin(BakePlugin::class);
        }

        if (!Plugin::isLoaded('Crustum/Ai')) {
            $this->addPlugin(new AiPlugin([
                'name' => 'Crustum/Ai',
                'path' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR,
            ]));
        }
    }

    /**
     * @param \Cake\Routing\RouteBuilder $routes Route builder
     * @return void
     */
    #[Override]
    public function routes(RouteBuilder $routes): void
    {
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue Middleware queue.
     * @return \Cake\Http\MiddlewareQueue
     */
    #[Override]
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue;
    }
}
