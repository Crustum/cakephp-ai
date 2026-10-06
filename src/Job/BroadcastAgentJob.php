<?php
declare(strict_types=1);

namespace Crustum\Ai\Job;

use Cake\Queue\Job\Message;
use Cake\Utility\Text;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Attributes\WithoutBroadcasting;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Providers\Provider as AbstractProvider;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\StreamEvent;
use Interop\Queue\Processor;
use Throwable;

/**
 * Stream an agent and broadcast events asynchronously via Cake Queue.
 *
 * Stateless by contract: the job is built with no constructor arguments
 * (`AiJobProcessor` / `Message::getCallable()`) and all job data travels in
 * the message payload. Business logic lives in `run()`; the lifecycle
 * (completion callbacks, `failed()` hook, ACK mapping) is owned by
 * `Crustum\Ai\Queue\AiJobProcessor`.
 */
class BroadcastAgentJob implements AiJobInterface
{
    use DispatchableTrait;

    /**
     * Handle a job failure by broadcasting a stream_failed event.
     *
     * Invoked by `AiJobProcessor`, never by userland. Channels and invocation
     * id come from the message payload — the same values `run()` uses.
     *
     * @param \Throwable $exception The exception
     * @param array<string, mixed> $data Job payload
     */
    public function failed(Throwable $exception, array $data = []): void
    {
        /** @var \Crustum\Broadcasting\Channel\Channel|mixed|array<\Crustum\Broadcasting\Channel\Channel> $channels */
        $channels = static::unpack($data['channels'] ?? null);
        $invocationId = isset($data['invocationId']) && is_string($data['invocationId'])
            ? $data['invocationId']
            : Text::uuid();

        (new Error(
            id: Text::uuid(),
            type: 'stream_failed',
            message: 'The stream failed.',
            recoverable: false,
            timestamp: time(),
        ))->withInvocationId($invocationId)
            ->broadcastNow($channels);
    }

    /**
     * Build a serializable queue payload.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent to invoke
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param \Crustum\Broadcasting\Channel\Channel|mixed|array<\Crustum\Broadcasting\Channel\Channel> $channels Broadcast channels
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|\Crustum\Ai\Providers\Provider|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @param string|null $invocationId Stream invocation id
     * @return array<string, mixed>
     */
    public static function payload(
        Agent $agent,
        Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        Lab|array|string|AbstractProvider|null $provider = null,
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
     * @param \Crustum\Ai\Enums\Lab|\Crustum\Ai\Providers\Provider|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return void
     */
    public static function dispatch(
        Agent $agent,
        Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        Lab|array|string|AbstractProvider|null $provider = null,
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
        $this->response = $this->run($message->getArgument() ?? []);

        return Processor::ACK;
    }

    /**
     * Run the job from a decoded payload (also used by tests).
     *
     * @param array<string, mixed> $data Job payload
     * @return \Crustum\Ai\Responses\StreamedAgentResponse Streamed agent response
     */
    public function run(array $data): mixed
    {
        /** @var \Crustum\Ai\Contracts\Agent $agent */
        $agent = static::unpack($data['agent'] ?? null);
        /** @var \Crustum\Ai\Approvals\Decisions|string $prompt */
        $prompt = static::unpack($data['prompt'] ?? null) ?? '';
        /** @var \Crustum\Broadcasting\Channel\Channel|mixed|array<\Crustum\Broadcasting\Channel\Channel> $channels */
        $channels = static::unpack($data['channels'] ?? null);
        /** @var array<mixed> $attachments */
        $attachments = static::unpack($data['attachments'] ?? null) ?? [];
        /** @var \Crustum\Ai\Enums\Lab|\Crustum\Ai\Providers\Provider|array|string|null $provider */
        $provider = static::unpack($data['provider'] ?? null);
        $model = isset($data['model']) && is_string($data['model']) ? $data['model'] : null;
        $invocationId = isset($data['invocationId']) && is_string($data['invocationId'])
            ? $data['invocationId']
            : Text::uuid();

        $without = WithoutBroadcasting::eventsFor($agent);

        $streamedResponse = null;

        $agent->stream($prompt, $attachments, $provider, $model)
            ->each(function (StreamEvent $event) use ($without, $invocationId, $channels): void {
                if (WithoutBroadcasting::excludes($without, $event)) {
                    return;
                }

                $event->withInvocationId($invocationId)->broadcastNow($channels);
            })
            ->then(function (mixed $response) use (&$streamedResponse): void {
                $streamedResponse = $response;
            });

        return $streamedResponse;
    }
}
