<?php
declare(strict_types=1);

namespace Crustum\Ai\Command;

use Cake\Console\Arguments;
use Override;

/**
 * Bake an agent tool class.
 *
 * Usage:
 * ```
 * bin/cake bake tool LookupOrder
 * ```
 */
class BakeToolCommand extends BakeAiCommand
{
    /**
     * @var string
     */
    public string $pathFragment = 'Ai/Tools/';

    /**
     * @inheritDoc
     */
    protected function typeLabel(): string
    {
        return 'tool';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function classSuffix(): string
    {
        return 'Tool';
    }

    /**
     * @inheritDoc
     */
    protected function templateName(Arguments $args): string
    {
        return 'Tool/tool';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'bake tool';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake an Ai tool class';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'tool';
    }
}
