<?php
declare(strict_types=1);

namespace Crustum\Ai\Job;

use Cake\Queue\QueueManager;
use Crustum\Ai\Queue\AiQueue;

/**
 * Self-dispatch helpers for Cake Queue jobs.
 */
trait DispatchableTrait
{
    /**
     * Response produced by the last `run()`.
     *
     * @var mixed
     */
    protected mixed $response = null;

    /**
     * Get the response produced by the last `run()`.
     *
     * @return mixed The produced response, or null when the job has not run yet
     */
    public function getResponse(): mixed
    {
        return $this->response;
    }

    /**
     * Push this job class onto the queue.
     *
     * Always lands on the dedicated Ai queue (never `default`); the queue
     * contract is enforced fail-fast.
     *
     * @param array<string, mixed> $data Job payload
     * @param array<string, mixed> $overrides Queue config overrides
     * @return void
     */
    public static function push(array $data = [], array $overrides = []): void
    {
        $options = array_merge(static::queueConfig(), $overrides);
        AiQueue::assertConfigured(
            isset($options['config']) && is_string($options['config']) ? $options['config'] : null,
        );

        QueueManager::push(static::class, $data, $options);
    }

    /**
     * Default queue configuration for the job.
     *
     * @return array<string, mixed>
     */
    protected static function queueConfig(): array
    {
        return AiQueue::defaultOptions();
    }

    /**
     * Pack a value for JSON-safe Cake Queue transport.
     *
     * Values may contain `SerializableClosure` instances (e.g. header/option
     * resolvers, queued completion callbacks), which only PHP serialization
     * can carry across the process boundary. Queue content is therefore
     * trusted the same way Laravel trusts it when shipping closures.
     *
     * @param mixed $value Value to pack
     * @return string
     */
    public static function pack(mixed $value): string
    {
        return base64_encode(serialize($value));
    }

    /**
     * Unpack a value from Cake Queue transport.
     *
     * @param mixed $value Packed value
     */
    public static function unpack(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return unserialize(base64_decode($value, true) ?: '');
    }
}
