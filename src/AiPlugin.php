<?php
declare(strict_types=1);

namespace Crustum\Ai;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Event\EventInterface;
use Crustum\Ai\Command\BakeAgentCommand;
use Crustum\Ai\Command\BakeAgentMiddlewareCommand;
use Crustum\Ai\Command\BakeToolCommand;
use Crustum\Ai\Command\ChatCommand;
use Crustum\Ai\Registry\ProviderRegistry;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestTrait;
use Override;

/**
 * AI Plugin for CakePHP
 *
 * Provides AI capabilities through various providers (OpenAI, Anthropic, etc.).
 *
 * @uses \Crustum\PluginManifest\Manifest\ManifestTrait
 */
class AiPlugin extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

    /**
     * Plugin name
     */
    protected $name = 'Ai';

    /**
     * Do bootstrapping or not
     */
    protected $bootstrapEnabled = true;

    /**
     * Load routes or not
     */
    protected $routesEnabled = false;

    /**
     * Console middleware enabled
     */
    protected $consoleEnabled = true;

    /**
     * HTTP middleware enabled
     */
    protected $middlewareEnabled = false;

    /**
     * Register plugin services in the container
     *
     * @param \Cake\Core\ContainerInterface $container Container instance
     * @return void
     */
    public function services(ContainerInterface $container): void
    {
        $container->addShared(ProviderRegistry::class);

        $container->addShared(AiManager::class)
            ->addArgument(ProviderRegistry::class);
    }

    /**
     * Bootstrap plugin
     *
     * @param \Cake\Core\PluginApplicationInterface $app Application instance
     * @return void
     */
    #[Override]
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        if (!$app instanceof ContainerApplicationInterface) {
            return;
        }

        $app->getEventManager()->on(
            'Application.buildContainer',
            function (EventInterface $event): void {
                $container = $event->getResult();
                if (!$container instanceof ContainerInterface) {
                    $container = $event->getData('container');
                }

                if ($container instanceof ContainerInterface) {
                    Ai::setContainer($container);
                }
            },
        );
    }

    /**
     * Register Ai console and bake commands.
     *
     * @param \Cake\Console\CommandCollection $commands Command collection
     * @return \Cake\Console\CommandCollection
     */
    #[Override]
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands = parent::console($commands);

        $commands->add(ChatCommand::defaultName(), ChatCommand::class);
        $commands->add(BakeAgentCommand::defaultName(), BakeAgentCommand::class);
        $commands->add(BakeToolCommand::defaultName(), BakeToolCommand::class);
        $commands->add(BakeAgentMiddlewareCommand::defaultName(), BakeAgentMiddlewareCommand::class);

        return $commands;
    }

    /**
     * Plugin install assets via crustum/plugin-manifest.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__);

        return array_merge(
            static::manifestMigrations(
                $pluginPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Migrations',
            ),
            static::manifestConfig(
                $pluginPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ai.php',
                CONFIG . 'ai.php',
                false,
            ),
            static::manifestBootstrapAppend(
                "if (file_exists(CONFIG . 'ai.php')) {\n    Configure::load('ai', 'default');\n}",
                '// Ai Plugin Configuration',
            ),
            static::manifestStarRepo('Crustum/Ai'),
        );
    }

    /**
     * Get plugin name
     *
     * @return string
     */
    #[Override]
    public function getName(): string
    {
        return $this->name;
    }
}
