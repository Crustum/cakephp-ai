<?php
declare(strict_types=1);

namespace Crustum\Ai\Queue;

use Cake\Core\Configure;
use Cake\Queue\QueueManager;
use InvalidArgumentException;
use Throwable;

/**
 * Dedicated queue contract for Ai jobs.
 *
 * Ai jobs always run under `AiJobProcessor` (lifecycle: payload callbacks,
 * `failed()` hook, poison-message rejection), which is a different processor
 * than the one consuming the application's `default` queue. Mixing the two
 * would route Ai messages through the base processor (silently dropping
 * callbacks) or foreign messages through the Ai processor. Therefore Ai jobs
 * never use `default`: they dispatch to a dedicated queue connection, and
 * every dispatch path enforces it fail-fast.
 *
 * The connection / queue names live in the plugin config (`config/ai.php`,
 * `Ai.queue.connection` / `Ai.queue.queue`, env-overridable) and default to
 * `ai` — they are not shared with, and never fall back to, the common queue.
 *
 * Host application setup (names matching the defaults):
 *
 * ```php
 * QueueManager::setConfig('ai', [
 *     'url' => 'redis://127.0.0.1:6379/0',
 *     'queue' => 'ai',
 *     'processor' => \Crustum\Ai\Queue\AiJobProcessor::class,
 * ]);
 * ```
 *
 * and run a worker consuming the `ai` queue (e.g.
 * `bin/cake queue worker --config ai --queue ai`).
 */
final class AiQueue
{
    /**
     * Fallback connection name when `Ai.queue.connection` is not configured.
     */
    public const DEFAULT_CONNECTION = 'ai';

    /**
     * Fallback broker queue name when `Ai.queue.queue` is not configured.
     */
    public const DEFAULT_QUEUE = 'ai';

    /**
     * Queue connection name Ai jobs dispatch to.
     *
     * @return string
     */
    public static function connection(): string
    {
        $connection = Configure::read('Ai.queue.connection', self::DEFAULT_CONNECTION);

        return is_string($connection) && $connection !== '' ? $connection : self::DEFAULT_CONNECTION;
    }

    /**
     * Broker queue name Ai jobs are routed to.
     *
     * @return string
     */
    public static function queue(): string
    {
        $queue = Configure::read('Ai.queue.queue', self::DEFAULT_QUEUE);

        return is_string($queue) && $queue !== '' ? $queue : self::DEFAULT_QUEUE;
    }

    /**
     * Default dispatch options for Ai jobs.
     *
     * @return array<string, mixed>
     */
    public static function defaultOptions(): array
    {
        return [
            'queue' => self::queue(),
            'config' => self::connection(),
        ];
    }

    /**
     * Enforce the dedicated-queue contract for an effective dispatch config.
     *
     * @param string|null $config Effective queue connection name
     * @throws \InvalidArgumentException When Ai jobs would land outside a properly configured Ai queue
     */
    public static function assertConfigured(?string $config = null): void
    {
        $name = $config ?? self::connection();

        if ($name === 'default') {
            throw new InvalidArgumentException(
                'Ai jobs must not use the `default` queue: configure a dedicated queue '
                . 'connection (`Ai.queue.connection`) with `processor` set to '
                . '`Crustum\Ai\Queue\AiJobProcessor`.',
            );
        }

        try {
            $queueConfig = QueueManager::getConfig($name);
        } catch (Throwable $throwable) {
            throw new InvalidArgumentException(
                sprintf(
                    'Ai jobs require a dedicated `%s` queue connection (`Ai.queue.connection`) '
                    . 'with `processor` set to `Crustum\Ai\Queue\AiJobProcessor`.',
                    $name,
                ),
                0,
                $throwable,
            );
        }

        if (!is_array($queueConfig)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Ai jobs require a dedicated `%s` queue connection (`Ai.queue.connection`) '
                    . 'with `processor` set to `Crustum\Ai\Queue\AiJobProcessor`.',
                    $name,
                ),
            );
        }

        $processor = $queueConfig['processor'] ?? null;
        if (!is_string($processor) || !is_a($processor, AiJobProcessor::class, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Ai queue connection `%s` must set `processor` to '
                    . '`Crustum\Ai\Queue\AiJobProcessor` (got `%s`).',
                    $name,
                    is_string($processor) ? $processor : get_debug_type($processor),
                ),
            );
        }
    }
}
