<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\QueuedAgentResponse;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Stringable;

/**
 * Agent Interface
 *
 * Defines the contract for AI agents that can process prompts and return responses.
 * Agents can operate synchronously, asynchronously (queued), or with streaming/broadcasting.
 */
interface Agent
{
    /**
     * Get the instructions that the agent should follow.
     *
     * Returns the system instructions or guidelines that define the agent's behavior,
     * personality, and operational parameters.
     *
     * @return \Stringable|string The agent's instructions.
     */
    public function instructions(): Stringable|string;

    /**
     * Invoke the agent with a given prompt.
     *
     * Executes the agent synchronously with the provided prompt and returns a complete response.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt The user's prompt or tool approval decisions.
     * @param array $attachments Optional attachments (images, files, etc.).
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The AI provider to use.
     * @param string|null $model The specific model to use.
     * @param int|null $timeout Request timeout in seconds.
     * @return \Crustum\Ai\Responses\AgentResponse The agent's response.
     */
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse;

    /**
     * Invoke the agent with a given prompt and return a streamable response.
     *
     * Executes the agent and returns a streaming response that can be consumed incrementally
     * as tokens are generated.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt The user's prompt or tool approval decisions.
     * @param array $attachments Optional attachments (images, files, etc.).
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The AI provider to use.
     * @param string|null $model The specific model to use.
     * @param int|null $timeout Request timeout in seconds.
     * @return \Crustum\Ai\Responses\StreamableAgentResponse The streamable response.
     */
    public function stream(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse;

    /**
     * Invoke the agent in a queued job.
     *
     * Queues the agent invocation for asynchronous processing, allowing the request
     * to return immediately without waiting for the AI response.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt The user's prompt or tool approval decisions.
     * @param array $attachments Optional attachments (images, files, etc.).
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The AI provider to use.
     * @param string|null $model The specific model to use.
     * @return \Crustum\Ai\Responses\QueuedAgentResponse The queued response handle.
     */
    public function queue(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse;

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     *
     * Executes the agent and broadcasts streaming events to the specified channels,
     * optionally processing in the background.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt The user's prompt or tool approval decisions.
     * @param mixed $channels The channel(s) to broadcast to.
     * @param array $attachments Optional attachments (images, files, etc.).
     * @param bool $now Whether to broadcast immediately (true) or queue it (false).
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The AI provider to use.
     * @param string|null $model The specific model to use.
     * @return \Crustum\Ai\Responses\StreamableAgentResponse The streamable response.
     */
    public function broadcast(
        AgentInput|UserMessage|Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse;

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events immediately.
     *
     * Executes the agent and immediately broadcasts streaming events to the specified channels
     * without queuing.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt The user's prompt or tool approval decisions.
     * @param mixed $channels The channel(s) to broadcast to.
     * @param array $attachments Optional attachments (images, files, etc.).
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The AI provider to use.
     * @param string|null $model The specific model to use.
     * @return \Crustum\Ai\Responses\StreamableAgentResponse The streamable response.
     */
    public function broadcastNow(
        AgentInput|UserMessage|Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse;

    /**
     * Queue the agent with a given prompt and broadcast the streamed events.
     *
     * Queues the agent invocation and configures it to broadcast streaming events
     * to the specified channels when processed.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt The user's prompt or tool approval decisions.
     * @param mixed $channels The channel(s) to broadcast to.
     * @param array $attachments Optional attachments (images, files, etc.).
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The AI provider to use.
     * @param string|null $model The specific model to use.
     * @return \Crustum\Ai\Responses\QueuedAgentResponse The queued response handle.
     */
    public function broadcastOnQueue(
        AgentInput|UserMessage|Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse;
}
