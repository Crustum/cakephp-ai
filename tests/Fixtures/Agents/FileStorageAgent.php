<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Tools\FileStorage;
use Crustum\Ai\Trait\PromptableTrait;

/**
 * Agent that exposes filesystem tools for end-to-end tests.
 */
class FileStorageAgent implements Agent, HasTools
{
    use PromptableTrait;

    /**
     * Get the agent instructions.
     *
     * @return string
     */
    public function instructions(): string
    {
        return 'You manage files on disk using the available tools.';
    }

    /**
     * Get the agent tools.
     *
     * @return iterable<int, \Crustum\Ai\Contracts\Tool>
     */
    public function tools(): iterable
    {
        return FileStorage::all();
    }
}
