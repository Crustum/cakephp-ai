<?php
declare(strict_types=1);

namespace Crustum\Ai\Trait;

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Cake\Utility\Text;
use Closure;
use Crustum\Ai\Ai;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Attributes\Model as ModelAttribute;
use Crustum\Ai\Attributes\Provider as ProviderAttribute;
use Crustum\Ai\Attributes\Timeout as TimeoutAttribute;
use Crustum\Ai\Attributes\UseCheapestModel;
use Crustum\Ai\Attributes\UseSmartestModel;
use Crustum\Ai\Attributes\WithoutBroadcasting;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Event\AgentFailedOverEvent;
use Crustum\Ai\Exception\FailoverableException;
use Crustum\Ai\Gateway\FakeTextGateway;
use Crustum\Ai\Gateway\ParentInvocation;
use Crustum\Ai\Job\BroadcastAgentJob;
use Crustum\Ai\Job\InvokeAgentJob;
use Crustum\Ai\Job\PendingDispatch;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Prompts\QueuedAgentPrompt;
use Crustum\Ai\Providers\Provider as AbstractProvider;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\QueuedAgentResponse;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Streaming\Event\StreamEvent;
use Generator;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;

/**
 * Promptable Trait
 *
 * Provides prompt, stream, queue, and broadcast capabilities for AI agents.
 */
trait PromptableTrait
{
    /**
     * Create a new instance of the agent.
     *
     * @param mixed ...$arguments Constructor arguments
     */
    public static function make(mixed ...$arguments): static
    {
        /** @var static $instance */
        $instance = match (true) {
            $arguments !== [] && !array_is_list($arguments) => Ai::make(static::class, $arguments),
            $arguments !== [] => new static(...$arguments),
            default => Ai::make(static::class),
        };

        return $instance;
    }

    /**
     * Invoke the agent with a given prompt, or resume a paused run with tool approval decisions.
     *
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @param int|null $timeout Request timeout in seconds
     * @return \Crustum\Ai\Responses\AgentResponse
     */
    public function prompt(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        [$text, $approvalDecisions] = $this->extractPromptInput($prompt);

        $invocationId = Text::uuid();

        $providers = $approvalDecisions !== null
            ? $this->providersForApprovalContinuation($provider, $model)
            : $this->getProvidersAndModelsForFailover($provider, $model);

        [$parentInvocationId, $parentToolInvocationId] = ParentInvocation::current();

        $run = (fn(TextProvider $provider, string $model, bool $isFinalAttempt = true): AgentResponse => $provider->prompt(
            new AgentPrompt(
                $this,
                $text,
                $attachments,
                $provider,
                $model,
                $this->getTimeout($timeout),
                $invocationId,
                $approvalDecisions,
                $parentInvocationId,
                $parentToolInvocationId,
                $isFinalAttempt,
            ),
        ));

        if ($approvalDecisions !== null) {
            [$resolved, $resolvedModel] = $this->iterateProvidersWithFailover($providers)->current();

            return $run($resolved, $resolvedModel);
        }

        return $this->withModelFailover($run, $providers, $invocationId);
    }

    /**
     * Invoke the agent with a given prompt and return a streamable response.
     *
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @param int|null $timeout Request timeout in seconds
     * @return \Crustum\Ai\Responses\StreamableAgentResponse
     */
    public function stream(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        [$text, $approvalDecisions] = $this->extractPromptInput($prompt);

        return $this->streamPrompt($text, $approvalDecisions, $attachments, $provider, $model, $timeout);
    }

    /**
     * Stream a text prompt or an approval continuation through the configured providers.
     *
     * @param string $prompt The prompt text
     * @param \Crustum\Ai\Approvals\Decisions|null $approvalDecisions Approval decisions to resume with
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @param int|null $timeout Request timeout in seconds
     * @return \Crustum\Ai\Responses\StreamableAgentResponse
     */
    private function streamPrompt(
        string $prompt,
        ?Decisions $approvalDecisions,
        array $attachments,
        Lab|array|string|null $provider,
        ?string $model,
        ?int $timeout,
    ): StreamableAgentResponse {
        $providers = $approvalDecisions instanceof Decisions
            ? $this->providersForApprovalContinuation($provider, $model)
            : $this->getProvidersAndModelsForFailover($provider, $model);
        $resolvedTimeout = $this->getTimeout($timeout);

        $invocationId = Text::uuid();

        [$parentInvocationId, $parentToolInvocationId] = ParentInvocation::current();

        if (count($providers) === 1) {
            [$resolved, $resolvedModel] = $this->iterateProvidersWithFailover($providers)->current();

            return $resolved->stream(
                new AgentPrompt($this, $prompt, $attachments, $resolved, $resolvedModel, $resolvedTimeout, $invocationId, $approvalDecisions, $parentInvocationId, $parentToolInvocationId),
            );
        }

        $meta = new Meta();
        $outer = null;

        $outer = new StreamableAgentResponse(
            $invocationId,
            function () use ($providers, $prompt, $approvalDecisions, $attachments, $resolvedTimeout, $invocationId, $parentInvocationId, $parentToolInvocationId, &$outer) {
                $lastException = null;

                foreach ($this->iterateProvidersWithFailover($providers) as [$provider, $model, $isFinalAttempt]) {
                    $innerResponse = null;

                    try {
                        $innerResponse = $provider->stream(
                            new AgentPrompt($this, $prompt, $attachments, $provider, $model, $resolvedTimeout, $invocationId, $approvalDecisions, $parentInvocationId, $parentToolInvocationId, $isFinalAttempt),
                        );

                        $innerResponse->then(fn(StreamedAgentResponse $response): StreamableAgentResponse => $outer->adoptStateFrom($response));

                        foreach ($innerResponse as $event) {
                            yield $event;
                        }

                        return;
                    } catch (FailoverableException $e) {
                        if ($innerResponse?->hasYielded()) {
                            throw $e;
                        }

                        $lastException = $isFinalAttempt
                            ? $e
                            : $this->recordAgentFailover($invocationId, $provider, $model, $e);
                    }
                }

                throw $lastException;
            },
            $meta,
        );

        return $outer;
    }

    /**
     * Invoke the agent in a queued job.
     *
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\QueuedAgentResponse
     */
    public function queue(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        if (static::isFaked()) {
            Ai::manager()->recordPrompt(
                new QueuedAgentPrompt($this, $prompt, $attachments, $provider, $model),
            );
        }

        return new QueuedAgentResponse(
            new PendingDispatch(
                InvokeAgentJob::class,
                InvokeAgentJob::payload($this, $prompt, $attachments, $provider, $model),
            ),
        );
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     *
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param mixed $channels The broadcast channels
     * @param array<mixed> $attachments Optional attachments
     * @param bool $now Whether to broadcast immediately
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\StreamableAgentResponse
     */
    public function broadcast(
        Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        $without = WithoutBroadcasting::eventsFor($this);

        return $this->stream($prompt, $attachments, $provider, $model)
            ->each(function (StreamEvent $event) use ($channels, $now, $without): void {
                if (WithoutBroadcasting::excludes($without, $event)) {
                    return;
                }

                $event->{$now ? 'broadcastNow' : 'broadcast'}($channels);
            });
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events immediately.
     *
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param mixed $channels The broadcast channels
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\StreamableAgentResponse
     */
    public function broadcastNow(
        Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        return $this->broadcast($prompt, $channels, $attachments, now: true, provider: $provider, model: $model);
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events on a queue.
     *
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param mixed $channels The broadcast channels
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\QueuedAgentResponse
     */
    public function broadcastOnQueue(
        Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        if (static::isFaked()) {
            Ai::manager()->recordPrompt(
                new QueuedAgentPrompt($this, $prompt, $attachments, $provider, $model),
            );
        }

        return new QueuedAgentResponse(
            new PendingDispatch(
                BroadcastAgentJob::class,
                BroadcastAgentJob::payload($this, $prompt, $channels, $attachments, $provider, $model),
            ),
        );
    }

    /**
     * Split a prompt input into its text and tool approval decisions.
     *
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @return array{0: string, 1: \Crustum\Ai\Approvals\Decisions|null}
     */
    private function extractPromptInput(Decisions|string $prompt): array
    {
        if (is_string($prompt)) {
            return [$prompt, null];
        }

        return ['', $prompt];
    }

    /**
     * Get the single provider / model pair an approval continuation must run against, since it may not fail over to a different provider.
     *
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return array<string, string|null>
     */
    private function providersForApprovalContinuation(Lab|array|string|null $provider, ?string $model): array
    {
        return array_slice($this->getProvidersAndModelsForFailover($provider, $model), 0, 1, true);
    }

    /**
     * Invoke the given Closure with provider / model failover.
     *
     * @param \Closure $callback The callback to invoke
     * @param array<string, string|null> $providers Provider and model map
     * @param string $invocationId Run invocation identifier
     */
    private function withModelFailover(Closure $callback, array $providers, string $invocationId): mixed
    {
        $lastException = null;

        foreach ($this->iterateProvidersWithFailover($providers) as [$provider, $model, $isFinalAttempt]) {
            try {
                return $callback($provider, $model, $isFinalAttempt);
            } catch (FailoverableException $e) {
                $lastException = $isFinalAttempt
                    ? $e
                    : $this->recordAgentFailover($invocationId, $provider, $model, $e);
            }
        }

        throw $lastException;
    }

    /**
     * Get the configured providers and models for failover.
     *
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return array<string, string|null>
     */
    private function getProvidersAndModelsForFailover(Lab|array|string|null $provider, ?string $model): array
    {
        $providers = $this->getProvidersAndModels($provider, $model);

        if (empty($providers)) {
            throw new RuntimeException('No AI providers were configured.');
        }

        return $providers;
    }

    /**
     * Iterate the configured provider / model pairs, flagging the attempt that has no provider left to fall back to.
     *
     * @param array<string, string|null> $providers Provider and model map
     * @return \Generator<int, array{0: \Crustum\Ai\Contracts\Providers\TextProvider, 1: string, 2: bool}>
     */
    private function iterateProvidersWithFailover(array $providers): Generator
    {
        $remaining = count($providers);

        foreach ($providers as $provider => $model) {
            $provider = Ai::manager()->textProviderFor($this, $provider);

            yield [$provider, $model ?? $this->getDefaultModelFor($provider), --$remaining === 0];
        }
    }

    /**
     * Record that an agent failed over to the next configured provider.
     *
     * @param string $invocationId Run invocation identifier
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider The provider that failed
     * @param string $model The model that failed
     * @param \Crustum\Ai\Exception\FailoverableException $exception The failover exception
     * @return \Crustum\Ai\Exception\FailoverableException
     */
    private function recordAgentFailover(string $invocationId, Provider $provider, string $model, FailoverableException $exception): FailoverableException
    {
        EventManager::instance()->dispatch(new AgentFailedOverEvent($invocationId, $this, $provider, $model, $exception));

        return $exception;
    }

    /**
     * Get the providers and models array for the given initial provider and model values.
     *
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return array<string, string|null>
     */
    protected function getProvidersAndModels(Lab|array|string|null $provider, ?string $model): array
    {
        if (is_null($provider)) {
            if (method_exists($this, 'provider')) {
                $provider = $this->provider();
            } else {
                $attributes = (new ReflectionClass($this))->getAttributes(ProviderAttribute::class);

                $provider = $attributes === [] ? null : $attributes[0]->newInstance()->value;
            }
        }

        if (!is_array($provider) && is_null($model)) {
            if (method_exists($this, 'model')) {
                $model = $this->model();
            } else {
                $attributes = (new ReflectionClass($this))->getAttributes(ModelAttribute::class);

                $model = $attributes === [] ? null : $attributes[0]->newInstance()->value;
            }
        }

        $resolved = $provider ?? Configure::read('Ai.defaultProvider');

        if (is_array($resolved) && array_intersect(array_keys($resolved), ['text', 'image', 'audio', 'transcription', 'embedding', 'reranking'])) {
            throw new InvalidArgumentException('The "ai.default" config value must be a string provider name or a Lab enum, not an array.');
        }

        return AbstractProvider::formatProviderAndModelList($resolved, $model);
    }

    /**
     * Get the default model to use for the given provider.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider The text provider
     * @return string
     */
    protected function getDefaultModelFor(TextProvider $provider): string
    {
        $reflection = new ReflectionClass($this);

        if (!empty($reflection->getAttributes(UseSmartestModel::class))) {
            return $provider->smartestTextModel();
        }

        if (!empty($reflection->getAttributes(UseCheapestModel::class))) {
            return $provider->cheapestTextModel();
        }

        return $provider->defaultTextModel();
    }

    /**
     * Get the timeout to use for the agent prompt.
     *
     * @param int|null $timeout Request timeout in seconds
     * @return int
     */
    protected function getTimeout(?int $timeout): int
    {
        if (!is_null($timeout)) {
            return $timeout;
        }

        if (method_exists($this, 'timeout')) {
            return $this->timeout();
        }

        $attributes = (new ReflectionClass($this))->getAttributes(TimeoutAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->value;
        }

        return 60;
    }

    /**
     * Fake the responses returned by the agent.
     *
     * @param \Closure|array<mixed> $responses Responses to return
     * @return \Crustum\Ai\Gateway\FakeTextGateway
     */
    public static function fake(Closure|array $responses = []): FakeTextGateway
    {
        return Ai::manager()->fakeAgent(static::class, $responses);
    }

    /**
     * Assert that a prompt was received matching a given truth test.
     *
     * @param \Closure|string $callback Truth test callback or prompt text
     * @return void
     */
    public static function assertPrompted(Closure|string $callback): void
    {
        Ai::manager()->assertAgentWasPrompted(static::class, $callback);
    }

    /**
     * Assert that a certain number of prompts were received.
     *
     * @param int $times Expected number of prompts
     * @return void
     */
    public static function assertPromptedTimes(int $times = 1): void
    {
        Ai::manager()->assertAgentWasPromptedTimes(static::class, $times);
    }

    /**
     * Assert that a prompt was not received matching a given truth test.
     *
     * @param \Closure|string $callback Truth test callback or prompt text
     * @return void
     */
    public static function assertNotPrompted(Closure|string $callback): void
    {
        Ai::manager()->assertAgentNotPrompted(static::class, $callback);
    }

    /**
     * Assert that no prompts were received.
     *
     * @return void
     */
    public static function assertNeverPrompted(): void
    {
        Ai::manager()->assertAgentNeverPrompted(static::class);
    }

    /**
     * Assert that a queued prompt was received matching a given truth test.
     *
     * @param \Closure|string $callback Truth test callback or prompt text
     * @return void
     */
    public static function assertQueued(Closure|string $callback): void
    {
        Ai::manager()->assertAgentWasQueued(static::class, $callback);
    }

    /**
     * Assert that a queued prompt was not received matching a given truth test.
     *
     * @param \Closure|string $callback Truth test callback or prompt text
     * @return void
     */
    public static function assertNotQueued(Closure|string $callback): void
    {
        Ai::manager()->assertAgentNotQueued(static::class, $callback);
    }

    /**
     * Assert that no queued prompts were received.
     *
     * @return void
     */
    public static function assertNeverQueued(): void
    {
        Ai::manager()->assertAgentNeverQueued(static::class);
    }

    /**
     * Determine if the agent is currently faked.
     *
     * @return bool
     */
    public static function isFaked(): bool
    {
        return Ai::manager()->hasFakeGatewayFor(static::class);
    }
}
