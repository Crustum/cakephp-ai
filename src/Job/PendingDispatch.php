<?php
declare(strict_types=1);

namespace Crustum\Ai\Job;

use Cake\Queue\QueueManager;
use Closure;
use Crustum\Queue\Sync\SyncJobRunner;
use Crustum\Queue\Sync\SyncModeResolver;
use Throwable;

/**
 * Pending queued job dispatch handle.
 *
 * Mirrors Laravel's PendingDispatch: holds the job + payload and defers the actual
 * dispatch until the handle is destroyed, so `then()` / `catch()` callbacks can be
 * registered in between. When CrustumQueue's sync mode is enabled the job runs
 * in-process (via its SyncJobRunner) and the produced response is forwarded to the
 * "then" callbacks; otherwise the payload is pushed onto Cake Queue.
 */
class PendingDispatch
{
    /**
     * The currently executing sync dispatch context (nested dispatch guard).
     *
     * @var array{jobClass: class-string, then: array<int, \Closure>, catch: array<int, \Closure>}|null
     */
    protected static ?array $active = null;

    /**
     * "then" callbacks invoked after the job resolves.
     *
     * @var array<int, \Closure>
     */
    protected array $thenCallbacks = [];

    /**
     * "catch" callbacks invoked if the job fails.
     *
     * @var array<int, \Closure>
     */
    protected array $catchCallbacks = [];

    /**
     * Constructor.
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $payload Serialized job payload
     * @param array<string, mixed> $config Queue / dispatch configuration
     */
    public function __construct(
        protected string $jobClass,
        protected array $payload = [],
        protected array $config = [],
    ) {
    }

    /**
     * Get the underlying job handle to register completion callbacks.
     *
     * @return object
     */
    public function getJob(): object
    {
        return new class ($this) {
            /**
             * Constructor.
             *
             * @param \Crustum\Ai\Job\PendingDispatch $dispatch Pending dispatch
             */
            public function __construct(protected PendingDispatch $dispatch)
            {
            }

            /**
             * Register a callback invoked with the job response.
             *
             * @param \Closure $callback Response callback
             */
            public function then(Closure $callback): self
            {
                $this->dispatch->addThen($callback);

                return $this;
            }

            /**
             * Register a callback invoked if the job fails.
             *
             * @param \Closure $callback Failure callback
             */
            public function catch(Closure $callback): self
            {
                $this->dispatch->addCatch($callback);

                return $this;
            }
        };
    }

    /**
     * Register a callback invoked after the job resolves.
     *
     * @param \Closure $callback Response callback
     */
    public function addThen(Closure $callback): void
    {
        $this->thenCallbacks[] = $callback;
    }

    /**
     * Register a callback invoked if the job fails.
     *
     * @param \Closure $callback Failure callback
     */
    public function addCatch(Closure $callback): void
    {
        $this->catchCallbacks[] = $callback;
    }

    /**
     * Forward a successfully produced response to the active "then" callbacks.
     *
     * Called by jobs at the end of their in-process run.
     *
     * @param class-string $jobClass Job class
     * @param mixed $response Job response
     */
    public static function resolve(string $jobClass, mixed $response): void
    {
        if (static::$active === null || static::$active['jobClass'] !== $jobClass) {
            return;
        }

        foreach (static::$active['then'] as $callback) {
            $callback($response);
        }
    }

    /**
     * Forward a failure to the active "catch" callbacks.
     *
     * @param class-string $jobClass Job class
     * @param \Throwable $exception The failure
     */
    public static function fail(string $jobClass, Throwable $exception): void
    {
        if (static::$active === null || static::$active['jobClass'] !== $jobClass) {
            return;
        }

        foreach (static::$active['catch'] as $callback) {
            $callback($exception);
        }
    }

    /**
     * Dispatch the job when the handle is destroyed.
     */
    public function __destruct()
    {
        if (static::$active !== null) {
            return;
        }

        static::$active = [
            'jobClass' => $this->jobClass,
            'then' => $this->thenCallbacks,
            'catch' => $this->catchCallbacks,
        ];

        try {
            if (self::syncEnabled($this->jobClass, $this->payload, $this->config)) {
                $runner = SyncJobRunner::class;
                $runner::run($this->jobClass, $this->payload, $this->config);
            } else {
                QueueManager::push($this->jobClass, $this->payload, array_merge(static::queueConfig(), $this->config));
            }
        } catch (Throwable $throwable) {
            foreach (static::$active['catch'] as $callback) {
                $callback($throwable);
            }
        } finally {
            static::$active = null;
        }
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
     * Determine if CrustumQueue sync mode should execute the job in-process.
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job payload
     * @param array<string, mixed> $config Queue / dispatch configuration
     * @return bool
     */
    protected static function syncEnabled(string $jobClass, array $data, array $config): bool
    {
        $resolver = SyncModeResolver::class;

        if (!class_exists($resolver)) {
            return false;
        }

        $resolution = $resolver::resolve($jobClass, $data, $config);

        return (bool)$resolution['sync'];
    }
}
