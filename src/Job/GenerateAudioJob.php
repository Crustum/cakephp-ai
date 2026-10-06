<?php
declare(strict_types=1);

namespace Crustum\Ai\Job;

use Cake\Queue\Job\Message;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\PendingResponses\PendingAudioGeneration;
use Interop\Queue\Processor;

/**
 * Generate audio asynchronously via Cake Queue.
 *
 * Stateless by contract: the job is built with no constructor arguments
 * (`AiJobProcessor` / `Message::getCallable()`) and all job data travels in
 * the message payload. Business logic lives in `run()`; the lifecycle is
 * owned by `Crustum\Ai\Queue\AiJobProcessor`.
 */
class GenerateAudioJob implements AiJobInterface
{
    use DispatchableTrait;

    /**
     * Build a serializable queue payload.
     *
     * @param \Crustum\Ai\PendingResponses\PendingAudioGeneration $pendingAudio Pending audio request
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider Provider name
     * @param string|null $model Model name
     * @return array<string, mixed>
     */
    public static function payload(
        PendingAudioGeneration $pendingAudio,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): array {
        return [
            'pending' => static::pack($pendingAudio),
            'provider' => static::pack($provider),
            'model' => $model,
        ];
    }

    /**
     * Dispatch the job to Cake Queue.
     *
     * @param \Crustum\Ai\PendingResponses\PendingAudioGeneration $pendingAudio Pending audio request
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider Provider name
     * @param string|null $model Model name
     * @return void
     */
    public static function dispatch(
        PendingAudioGeneration $pendingAudio,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): void {
        static::push(static::payload($pendingAudio, $provider, $model));
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
     * @return mixed The generated audio response
     */
    public function run(array $data): mixed
    {
        /** @var \Crustum\Ai\PendingResponses\PendingAudioGeneration $pending */
        $pending = static::unpack($data['pending'] ?? null);
        /** @var \Crustum\Ai\Enums\Lab|array|string|null $provider */
        $provider = static::unpack($data['provider'] ?? null);
        $model = isset($data['model']) && is_string($data['model']) ? $data['model'] : null;

        return $pending->generate($provider, $model);
    }
}
