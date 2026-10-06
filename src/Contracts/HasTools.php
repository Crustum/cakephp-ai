<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

/**
 * HasTools Interface
 *
 * Allows agents to define a collection of tools that the AI agent can use to perform
 * specific actions or retrieve information during prompt processing.
 */
interface HasTools
{
    /**
     * Get the tools available to the agent.
     *
     * Returns an iterable collection of tools that the agent can invoke. Tools can be
     * custom Tool implementations or provider-specific ProviderTool instances.
     * The AI agent will have access to these tools and can choose to invoke them
     * based on the user's prompt and the tools' descriptions.
     *
     * @return list<\Crustum\Ai\Contracts\Agent|\Crustum\Ai\Contracts\Tool|\Crustum\Ai\Providers\Tools\ProviderTool> The available tools.
     */
    public function tools(): iterable;
}
