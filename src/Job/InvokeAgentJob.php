<?php
declare(strict_types=1);

namespace Crustum\Ai\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Enums\Lab;
use Interop\Queue\Processor;

/**
 * Invoke an agent asynchronously via Cake Queue.
 */
class InvokeAgentJob implements JobInterface
{
    use DispatchableTrait;

    /**
     * Build a serializable queue payload.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent to invoke
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return array<string, mixed>
     */
    public static function payload(
        Agent $agent,
        Decisions|string $prompt = '',
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): array {
        return [
            'agent' => static::pack($agent),
            'prompt' => static::pack($prompt),
            'attachments' => static::pack($attachments),
            'provider' => static::pack($provider),
            'model' => $model,
        ];
    }

    /**
     * Dispatch the job to Cake Queue.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent to invoke
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return void
     */
    public static function dispatch(
        Agent $agent,
        Decisions|string $prompt = '',
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): void {
        static::push(static::payload($agent, $prompt, $attachments, $provider, $model));
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
        /** @var array<mixed> $attachments */
        $attachments = static::unpack($data['attachments'] ?? null) ?? [];
        /** @var \Crustum\Ai\Enums\Lab|array|string|null $provider */
        $provider = static::unpack($data['provider'] ?? null);
        $model = isset($data['model']) && is_string($data['model']) ? $data['model'] : null;

        $response = $agent->prompt($prompt, $attachments, $provider, $model);

        PendingDispatch::resolve(static::class, $response);

        return Processor::ACK;
    }
}
