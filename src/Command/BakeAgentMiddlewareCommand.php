<?php
declare(strict_types=1);

namespace Crustum\Ai\Command;

use Cake\Console\Arguments;
use Override;

/**
 * Bake an agent middleware class.
 *
 * Usage:
 * ```
 * bin/cake bake agent_middleware LogPrompts
 * ```
 */
class BakeAgentMiddlewareCommand extends BakeAiCommand
{
    /**
     * @var string
     */
    public string $pathFragment = 'Ai/Middleware/';

    /**
     * @inheritDoc
     */
    protected function typeLabel(): string
    {
        return 'agent middleware';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function classSuffix(): string
    {
        return 'Middleware';
    }

    /**
     * @inheritDoc
     */
    protected function templateName(Arguments $args): string
    {
        return 'AgentMiddleware/agent_middleware';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'bake agent_middleware';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Bake an Ai agent middleware class';
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'agent_middleware';
    }
}
