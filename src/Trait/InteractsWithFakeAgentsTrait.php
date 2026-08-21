<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Closure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Gateway\FakeTextGateway;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Prompts\QueuedAgentPrompt;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Interacts With Fake Agents Trait
 *
 * Provides methods for faking agent interactions in tests.
 * Allows recording and asserting agent prompts for testing purposes.
 */
trait InteractsWithFakeAgentsTrait
{
    /**
     * All of the registered fake agent gateways.
     *
     * @var array<string, \Crustum\Ai\Gateway\FakeTextGateway>
     */
    protected array $fakeAgentGateways = [];

    /**
     * All of the recorded agent prompts.
     *
     * @var array<string, array<\Crustum\Ai\Prompts\AgentPrompt>>
     */
    protected array $recordedPrompts = [];

    /**
     * All of the recorded agent prompts that were queued.
     *
     * @var array<string, array<\Crustum\Ai\Prompts\QueuedAgentPrompt>>
     */
    protected array $recordedQueuedPrompts = [];

    /**
     * Fake the responses returned by the given agent.
     *
     * @param string $agent Agent class name
     * @param \Closure|array $responses Responses to return
     * @return \Crustum\Ai\Gateway\FakeTextGateway
     */
    public function fakeAgent(string $agent, Closure|array $responses = []): FakeTextGateway
    {
        $gateway = new FakeTextGateway($responses);
        $this->fakeAgentGateways[$agent] = $gateway;
        unset($this->recordedPrompts[$agent], $this->recordedQueuedPrompts[$agent]);

        return $gateway;
    }

    /**
     * Determine if the given agent has been faked.
     *
     * @param \Crustum\Ai\Contracts\Agent|string $agent Agent instance or class name
     * @return bool
     */
    public function hasFakeGatewayFor(Agent|string $agent): bool
    {
        return array_key_exists(
            is_object($agent) ? $agent::class : $agent,
            $this->fakeAgentGateways,
        );
    }

    /**
     * Get a fake gateway instance for the given agent.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @return \Crustum\Ai\Gateway\FakeTextGateway
     * @throws \InvalidArgumentException
     */
    public function fakeGatewayFor(Agent $agent): FakeTextGateway
    {
        return $this->hasFakeGatewayFor($agent)
            ? $this->fakeAgentGateways[$agent::class]
            : throw new InvalidArgumentException('Agent [' . $agent::class . '] has not been faked.');
    }

    /**
     * Record the given prompt for the faked agent.
     *
     * @param \Crustum\Ai\Prompts\AgentPrompt|\Crustum\Ai\Prompts\QueuedAgentPrompt $prompt The prompt
     * @return $this
     */
    public function recordPrompt(AgentPrompt|QueuedAgentPrompt $prompt)
    {
        if ($prompt instanceof QueuedAgentPrompt) {
            $this->recordedQueuedPrompts[$prompt->agent::class][] = $prompt;
        } else {
            $this->recordedPrompts[$prompt->agent::class][] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that a prompt was received matching a given truth test.
     *
     * @param string $agent Agent class name
     * @param \Closure|string $callback Truth test callback or prompt text
     * @param array|null $prompts Prompts to check (defaults to recorded prompts)
     * @param string|null $message Custom assertion message
     * @return $this
     */
    public function assertAgentWasPrompted(
        string $agent,
        Closure|string $callback,
        ?array $prompts = null,
        ?string $message = null,
    ) {
        $callback = is_string($callback)
            ? fn($prompt): bool => $prompt->prompt === $callback
            : $callback;

        PHPUnit::assertTrue(
            collection($prompts ?? $this->recordedPrompts[$agent] ?? [])->some(fn($prompt) => $callback($prompt)),
            $message ?? 'An expected prompt was not received.',
        );

        return $this;
    }

    /**
     * Assert that a certain number of prompts were received.
     *
     * @param string $agent Agent class name
     * @param int $times Expected number of prompts
     * @return $this
     */
    public function assertAgentWasPromptedTimes(string $agent, int $times = 1)
    {
        $count = count($this->recordedPrompts[$agent] ?? []);

        PHPUnit::assertSame(
            $times,
            $count,
            sprintf(
                'Received %d %s instead of %d %s.',
                $count,
                $count === 1 ? 'prompt' : 'prompts',
                $times,
                $times === 1 ? 'prompt' : 'prompts',
            ),
        );

        return $this;
    }

    /**
     * Assert that a prompt was received matching a given truth test.
     *
     * @param string $agent Agent class name
     * @param \Closure|string $callback Truth test callback or prompt text
     * @return $this
     */
    public function assertAgentWasQueued(string $agent, Closure|string $callback)
    {
        return $this->assertAgentWasPrompted(
            $agent,
            $callback,
            $this->recordedQueuedPrompts[$agent] ?? [],
            'An expected queued prompt was not received.',
        );
    }

    /**
     * Assert that a prompt was not received matching a given truth test.
     *
     * @param string $agent Agent class name
     * @param \Closure|string $callback Truth test callback or prompt text
     * @param array|null $prompts Prompts to check (defaults to recorded prompts)
     * @param string|null $message Custom assertion message
     * @return $this
     */
    public function assertAgentNotPrompted(
        string $agent,
        Closure|string $callback,
        ?array $prompts = null,
        ?string $message = null,
    ) {
        $callback = is_string($callback)
            ? fn($prompt): bool => $prompt->prompt === $callback
            : $callback;

        PHPUnit::assertFalse(
            collection($prompts ?? $this->recordedPrompts[$agent] ?? [])->some(fn($prompt) => $callback($prompt)),
            $message ?? 'An unexpected prompt was received.',
        );

        return $this;
    }

    /**
     * Assert that a queued prompt was not received matching a given truth test.
     *
     * @param string $agent Agent class name
     * @param \Closure|string $callback Truth test callback or prompt text
     * @return $this
     */
    public function assertAgentNotQueued(string $agent, Closure|string $callback)
    {
        return $this->assertAgentNotPrompted(
            $agent,
            $callback,
            $this->recordedQueuedPrompts[$agent] ?? [],
            'An unexpected queued prompt was received.',
        );
    }

    /**
     * Assert that no prompts were received.
     *
     * @param string $agent Agent class name
     * @return $this
     */
    public function assertAgentNeverPrompted(string $agent)
    {
        PHPUnit::assertEmpty(
            $this->recordedPrompts[$agent] ?? [],
            'An unexpected prompt was received.',
        );

        return $this;
    }

    /**
     * Assert that no queued prompts were received.
     *
     * @param string $agent Agent class name
     * @return $this
     */
    public function assertAgentNeverQueued(string $agent)
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedPrompts[$agent] ?? [],
            'An unexpected queued prompt was received.',
        );

        return $this;
    }

    /**
     * Get recorded prompts for a faked agent, or all recorded prompts keyed by agent class.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent>|null $agent Optional agent class
     * @return ($agent is null ? array<string, array<\Crustum\Ai\Prompts\AgentPrompt>> : array<int, \Crustum\Ai\Prompts\AgentPrompt>)
     */
    public function recordedAgentPrompts(?string $agent = null): array
    {
        if ($agent === null) {
            return $this->recordedPrompts;
        }

        return array_values($this->recordedPrompts[$agent] ?? []);
    }

    /**
     * Get agent classes that recorded at least one prompt.
     *
     * @return list<class-string<\Crustum\Ai\Contracts\Agent>>
     */
    public function promptedAgentClasses(): array
    {
        $classes = [];

        foreach ($this->recordedPrompts as $agentClass => $prompts) {
            if ($prompts !== []) {
                $classes[] = $agentClass;
            }
        }

        return $classes;
    }
}
