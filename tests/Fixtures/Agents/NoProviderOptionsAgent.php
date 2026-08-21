<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Trait\PromptableTrait;

class NoProviderOptionsAgent implements Agent
{
    use PromptableTrait;

    public function instructions(): string
    {
        return 'test';
    }
}
