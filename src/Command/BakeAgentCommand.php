<?php
declare(strict_types=1);

namespace Crustum\Ai\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleOptionParser;
use Override;

/**
 * Bake an agent class.
 *
 * Usage:
 * ```
 * bin/cake bake agent ResearchAgent
 * bin/cake bake agent ResearchAgent --structured
 * ```
 */
class BakeAgentCommand extends BakeAiCommand
{
    /**
     * @var string
     */
    public string $pathFragment = 'Ai/Agents/';

    /**
     * @inheritDoc
     */
    protected function typeLabel(): string
    {
        return 'agent';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function classSuffix(): string
    {
        return 'Agent';
    }

    /**
     * @inheritDoc
     */
    protected function templateName(Arguments $args): string
    {
        if ($args->getOption('structured')) {
            return 'Agent/structured_agent';
        }

        return 'Agent/agent';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);
        $parser->addOption('structured', [
            'help' => 'Generate a structured-output agent.',
            'boolean' => true,
            'default' => false,
        ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'bake agent';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake an Ai agent class';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'agent';
    }
}
