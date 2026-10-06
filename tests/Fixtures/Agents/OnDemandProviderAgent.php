<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Ai;
use Crustum\Ai\Providers\Provider;

class OnDemandProviderAgent extends AssistantAgent
{
    public function __construct(public string $key)
    {
    }

    public function provider(): Provider
    {
        return Ai::manager()->build(['driver' => 'anthropic', 'key' => $this->key]);
    }
}
