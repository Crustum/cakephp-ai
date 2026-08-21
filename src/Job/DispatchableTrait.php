<?php
declare(strict_types=1);

namespace Crustum\Ai\Job;

use Cake\Queue\QueueManager;

/**
 * Self-dispatch helpers for Cake Queue jobs.
 */
trait DispatchableTrait
{
    /**
     * Push this job class onto the queue.
     *
     * @param array<string, mixed> $data Job payload
     * @param array<string, mixed> $overrides Queue config overrides
     * @return void
     */
    public static function push(array $data = [], array $overrides = []): void
    {
        QueueManager::push(static::class, $data, array_merge(static::queueConfig(), $overrides));
    }

    /**
     * Default queue configuration for the job.
     *
     * @return array<string, mixed>
     */
    protected static function queueConfig(): array
    {
        return [
            'queue' => 'default',
            'config' => 'default',
        ];
    }

    /**
     * Pack a value for JSON-safe Cake Queue transport.
     *
     * @param mixed $value Value to pack
     * @return string
     */
    protected static function pack(mixed $value): string
    {
        return base64_encode(serialize($value));
    }

    /**
     * Unpack a value from Cake Queue transport.
     *
     * @param mixed $value Packed value
     */
    protected static function unpack(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return unserialize(base64_decode($value, true) ?: '');
    }
}
