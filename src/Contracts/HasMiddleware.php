<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

/**
 * HasMiddleware Interface
 *
 * Allows agents to define middleware that intercepts and potentially modifies prompts
 * before they are sent to the AI provider.
 */
interface HasMiddleware
{
    /**
     * Get the middleware wrapping each generation step of the agent.
     *
     * Returns an array of middleware classes or callables that will be applied to each
     * generation step in the order they are defined.
     *
     * @return array The middleware stack.
     */
    public function middleware(): array;
}
