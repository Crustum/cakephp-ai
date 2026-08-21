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
     * Get the agent's prompt middleware.
     *
     * Returns an array of middleware classes or callables that will be applied to prompts
     * in the order they are defined. Middleware can modify prompts, add context, enforce
     * policies, or perform other transformations.
     *
     * @return array The middleware stack.
     */
    public function middleware(): array;
}
