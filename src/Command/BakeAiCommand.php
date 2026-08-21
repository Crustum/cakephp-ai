<?php
declare(strict_types=1);

namespace Crustum\Ai\Command;

use Bake\Command\BakeCommand;
use Bake\Utility\TemplateRenderer;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Override;

/**
 * Shared bake generator for Ai stubs.
 */
abstract class BakeAiCommand extends BakeCommand
{
    /**
     * Human label for log output (e.g. agent, tool).
     *
     * @return string
     */
    abstract protected function typeLabel(): string;

    /**
     * TemplateRenderer path under Crustum/Ai (e.g. Agent/agent).
     *
     * @param \Cake\Console\Arguments $args CLI arguments
     * @return string
     */
    abstract protected function templateName(Arguments $args): string;

    /**
     * Output path under the application or plugin source directory.
     *
     * @return string
     */
    abstract protected function outputPathFragment(): string;

    /**
     * Optional class name suffix (e.g. Tool). Empty string to skip.
     *
     * @return string
     */
    protected function classSuffix(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $name = $args->getArgumentAt(0);

        if ($name === null || $name === '') {
            $io->err(sprintf('<error>You must provide a %s name.</error>', $this->typeLabel()));
            $io->out(sprintf('Example: bin/cake %s Example', static::defaultName()));

            return static::CODE_ERROR;
        }

        $name = $this->_getName($name);
        $suffix = $this->classSuffix();

        if ($suffix !== '' && !str_ends_with($name, $suffix)) {
            $name .= $suffix;
        }

        $content = $this->getContent($name, $args, $io);

        if ($content === false || $content === '') {
            $io->err(sprintf(
                "<warning>No generated content for '%s', not generating template.</warning>",
                $name,
            ));

            return static::CODE_ERROR;
        }

        $this->bake($name, $args, $io, $content);

        return static::CODE_SUCCESS;
    }

    /**
     * Write the generated class file.
     *
     * @param string $name Class name
     * @param \Cake\Console\Arguments $args CLI arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string|bool $content Generated content
     * @return void
     */
    public function bake(string $name, Arguments $args, ConsoleIo $io, string|bool $content): void
    {
        $path = $this->getPath($args);
        $filename = $path . $name . '.php';
        $io->out("\n" . sprintf('Baking %s class for %s...', $this->typeLabel(), $name), 1, ConsoleIo::QUIET);

        if (is_string($content) && $args->getOption('verbose')) {
            $io->out($content);
        }

        if (is_string($content)) {
            $forceOption = $args->getOption('force');
            $force = is_bool($forceOption) && $forceOption;
            $io->createFile($filename, $content, $force);
        }

        $this->deleteEmptyFile($path . '.gitkeep', $io);
    }

    /**
     * Render template content for the class.
     *
     * @param string $name Class name
     * @param \Cake\Console\Arguments $args CLI arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return string|bool
     */
    public function getContent(string $name, Arguments $args, ConsoleIo $io): string|bool
    {
        $namespace = Configure::read('App.namespace');

        if ($this->plugin) {
            $namespace = $this->_pluginNamespace($this->plugin);
        }

        $namespace .= '\\' . str_replace('/', '\\', rtrim($this->outputPathFragment(), '/'));

        $vars = [
            'namespace' => $namespace,
            'class' => $name,
        ];

        $themeOption = $args->getOption('theme');
        $theme = is_string($themeOption) ? $themeOption : null;
        $renderer = new TemplateRenderer($theme);
        $renderer->set('plugin', $this->plugin);
        $renderer->set($vars);

        return $renderer->generate('Crustum/Ai.' . $this->templateName($args));
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getPath(Arguments $args): string
    {
        $pathFragment = $this->outputPathFragment();
        $path = APP . $pathFragment;

        if ($this->plugin) {
            $path = $this->_pluginPath($this->plugin) . 'src/' . $pathFragment;
        }

        $prefix = $this->getPrefix($args);

        if ($prefix !== '' && $prefix !== '0') {
            $path .= $prefix . DIRECTORY_SEPARATOR;
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = $this->_setCommonOptions($parser);
        $parser->setDescription(static::getDescription())
            ->addArgument('name', [
                'help' => sprintf('Name of the %s class to generate.', $this->typeLabel()),
                'required' => true,
            ]);

        return $parser;
    }
}
