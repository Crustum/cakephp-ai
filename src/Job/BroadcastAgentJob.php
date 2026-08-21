<?php
declare(strict_types=1);

namespace Crustum\Ai\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Cake\Utility\Text;
use Closure;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Attributes\WithoutBroadcasting;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\StreamEvent;
use Interop\Queue\Processor;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

/**
 * Stream an agent and broadcast events asynchronously via Cake Queue.
 */
class BroadcastAgentJob implements JobInterface
{
    use DispatchableTrait;

    /**
     * Stream invocation id.
     */
    public string $invocationId;

    /**
     * "then" callbacks invoked after the agent resolves.
     *
     * @var array<int, \Closure|\Laravel\SerializableClosure\SerializableClosure>
     */
    protected array $thenCallbacks = [];

    /**
     * Create a new job instance.
     *
     * @param \Crustum\Ai\Contracts\Agent|null $agent The agent to invoke
     * @param \Crustum\Ai\Approvals\Decisions|string|null $prompt The prompt text or approval decisions
     * @param mixed $channels Broadcast channels
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     */
    public function __construct(
        public ?Agent $agent = null,
        public Decisions|string|null $prompt = null,
        public mixed $channels = null,
        public array $attachments = [],
        public Lab|array|string|null $provider = null,
        public ?string $model = null,
    ) {
        $this->invocationId = Text::uuid();
    }

    /**
     * Execute the job directly (also used by tests).
     */
    public function handle(): void
    {
        $streamedResponse = null;

        $without = WithoutBroadcasting::eventsFor($this->agent);

        $this->agent->stream($this->prompt, $this->attachments, $this->provider, $this->model)
            ->each(function (StreamEvent $event) use ($without): void {
                if (WithoutBroadcasting::excludes($without, $event)) {
                    return;
                }

                $event->withInvocationId($this->invocationId)->broadcastNow($this->channels);
            })
            ->then(function ($response) use (&$streamedResponse): void {
                $streamedResponse = $response;
            });

        $this->withCallbacks(fn() => $streamedResponse);
    }

    /**
     * Add a callback to be executed after the agent is invoked.
     *
     * @param \Closure $callback Response callback
     */
    public function then(Closure $callback): self
    {
        $this->thenCallbacks[] = new SerializableClosure($callback);

        return $this;
    }

    /**
     * Invoke the given action then invoke the "then" callbacks.
     *
     * @param \Closure $action Action
     * @return mixed
     */
    protected function withCallbacks(Closure $action): mixed
    {
        $response = $action();

        foreach ($this->thenCallbacks as $callback) {
            $callback($response);
        }

        return $response;
    }

    /**
     * Handle a job failure by broadcasting a stream_failed event.
     *
     * @param \Throwable $exception The exception
     */
    public function failed(Throwable $exception): void
    {
        (new Error(
            id: Text::uuid(),
            type: 'stream_failed',
            message: 'The stream failed.',
            recoverable: false,
            timestamp: time(),
        ))->withInvocationId($this->invocationId)
            ->broadcastNow($this->channels);
    }

    /**
     * Get the display name for the queued job.
     *
     * @return string
     */
    public function displayName(): string
    {
        return $this->agent ? $this->agent::class : static::class;
    }

    /**
     * Build a serializable queue payload.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent to invoke
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param \Crustum\Broadcasting\Channel\Channel|mixed|array<\Crustum\Broadcasting\Channel\Channel> $channels Broadcast channels
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @param string|null $invocationId Stream invocation id
     * @return array<string, mixed>
     */
    public static function payload(
        Agent $agent,
        Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?string $invocationId = null,
    ): array {
        return [
            'agent' => static::pack($agent),
            'prompt' => static::pack($prompt),
            'channels' => static::pack($channels),
            'attachments' => static::pack($attachments),
            'provider' => static::pack($provider),
            'model' => $model,
            'invocationId' => $invocationId ?? Text::uuid(),
        ];
    }

    /**
     * Dispatch the job to Cake Queue.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent to invoke
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param \Crustum\Broadcasting\Channel\Channel|mixed|array<\Crustum\Broadcasting\Channel\Channel> $channels Broadcast channels
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return void
     */
    public static function dispatch(
        Agent $agent,
        Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): void {
        static::push(static::payload($agent, $prompt, $channels, $attachments, $provider, $model));
    }

    /**
     * Execute the job from a queue message.
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        return $this->run($message->getArgument() ?? []);
    }

    /**
     * Run the job from a decoded payload (also used by tests).
     *
     * @param array<string, mixed> $data Job payload
     * @return string
     */
    public function run(array $data): string
    {
        /** @var \Crustum\Ai\Contracts\Agent $agent */
        $agent = static::unpack($data['agent'] ?? null);
        /** @var \Crustum\Ai\Approvals\Decisions|string $prompt */
        $prompt = static::unpack($data['prompt'] ?? null) ?? '';
        /** @var \Crustum\Broadcasting\Channel\Channel|mixed|array<\Crustum\Broadcasting\Channel\Channel> $channels */
        $channels = static::unpack($data['channels'] ?? null);
        /** @var array<mixed> $attachments */
        $attachments = static::unpack($data['attachments'] ?? null) ?? [];
        /** @var \Crustum\Ai\Enums\Lab|array|string|null $provider */
        $provider = static::unpack($data['provider'] ?? null);
        $model = isset($data['model']) && is_string($data['model']) ? $data['model'] : null;
        $invocationId = isset($data['invocationId']) && is_string($data['invocationId'])
            ? $data['invocationId']
            : Text::uuid();

        $without = WithoutBroadcasting::eventsFor($agent);

        $agent->stream($prompt, $attachments, $provider, $model)
            ->each(function (StreamEvent $event) use ($without, $invocationId, $channels): void {
                if (WithoutBroadcasting::excludes($without, $event)) {
                    return;
                }

                $event->withInvocationId($invocationId)->broadcastNow($channels);
            })
            ->then(function (mixed $response): void {
                PendingDispatch::resolve(static::class, $response);
            });

        return Processor::ACK;
    }
}
