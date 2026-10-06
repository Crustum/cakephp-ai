<?php
declare(strict_types=1);

namespace Crustum\Ai\Job;

use Cake\Queue\Job\Message;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Providers\Provider as AbstractProvider;
use Interop\Queue\Processor;

/**
 * Invoke an agent asynchronously via Cake Queue.
 *
 * Stateless by contract: the job is built with no constructor arguments
 * (`AiJobProcessor` / `Message::getCallable()`) and all job data travels in
 * the message payload. Business logic lives in `run()`; the lifecycle is
 * owned by `Crustum\Ai\Queue\AiJobProcessor`.
 */
class InvokeAgentJob implements AiJobInterface
{
    use DispatchableTrait;

    /**
     * Build a serializable queue payload.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent The agent to invoke
     * @param \Crustum\Ai\Approvals\Decisions|string $prompt The prompt text or approval decisions
     * @param array<mixed> $attachments Optional attachments
     * @param \Crustum\Ai\Enums\Lab|\Crustum\Ai\Providers\Provider|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return array<string, mixed>
     */
    public static function payload(
        Agent $agent,
        Decisions|string $prompt = '',
        array $attachments = [],
        Lab|array|string|AbstractProvider|null $provider = null,
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
     * @param \Crustum\Ai\Enums\Lab|\Crustum\Ai\Providers\Provider|array|string|null $provider The provider to use
     * @param string|null $model The model to use
     * @return void
     */
    public static function dispatch(
        Agent $agent,
        Decisions|string $prompt = '',
        array $attachments = [],
        Lab|array|string|AbstractProvider|null $provider = null,
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
        $this->response = $this->run($message->getArgument() ?? []);

        return Processor::ACK;
    }

    /**
     * Run the job from a decoded payload (also used by tests).
     *
     * @param array<string, mixed> $data Job payload
     * @return mixed The produced agent response
     */
    public function run(array $data): mixed
    {
        /** @var \Crustum\Ai\Contracts\Agent $agent */
        $agent = static::unpack($data['agent'] ?? null);
        /** @var \Crustum\Ai\Approvals\Decisions|string $prompt */
        $prompt = static::unpack($data['prompt'] ?? null) ?? '';
        /** @var array<mixed> $attachments */
        $attachments = static::unpack($data['attachments'] ?? null) ?? [];
        /** @var \Crustum\Ai\Enums\Lab|\Crustum\Ai\Providers\Provider|array|string|null $provider */
        $provider = static::unpack($data['provider'] ?? null);
        $model = isset($data['model']) && is_string($data['model']) ? $data['model'] : null;

        return $agent->prompt($prompt, $attachments, $provider, $model);
    }
}
