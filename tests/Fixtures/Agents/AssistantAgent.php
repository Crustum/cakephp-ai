<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasMiddleware;
use Crustum\Ai\Trait\PromptableTrait;

class AssistantAgent implements Agent, HasMiddleware
{
    use PromptableTrait;

    protected $middleware = [];

    public function instructions(): string
    {
        return 'You are a helpful assistant that responds extremely concisely to all queries.';
    }

    public function middleware(): array
    {
        return $this->middleware;
    }

    public function withMiddleware(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }
}
