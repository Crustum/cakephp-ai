<?php
declare(strict_types=1);

namespace Crustum\Ai\Job;

use Cake\Log\Log;
use Cake\Queue\QueueManager;
use Closure;
use Crustum\Ai\Contracts\PendingDispatchInterface;
use Crustum\Ai\Queue\AiQueue;
use Crustum\Queue\Sync\SyncJobRunner;
use Crustum\Queue\Sync\SyncModeResolver;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

/**
 * Pending queued job dispatch handle.
 *
 * PendingDispatch holds the job + payload and defers the actual dispatch
 * until the handle is destroyed, so `then()` / `catch()` callbacks can be
 * registered in between. Completion callbacks travel inside the payload
 * (packed `SerializableClosure` strings under the `then` / `catch` keys, only
 * present when callbacks were registered) and are executed by
 * `Crustum\Ai\Queue\AiJobProcessor` wherever the job runs — worker, sync, or
 * tests. When CrustumQueue's sync mode is enabled the job runs in-process via
 * its SyncJobRunner (which honors the configured processor); otherwise the
 * payload is pushed onto Cake Queue.
 */
class PendingDispatch implements PendingDispatchInterface
{
    /**
     * Packed "then" callbacks invoked after the job resolves.
     *
     * Stored packed (not raw) so unserializable callbacks — e.g. closures
     * with by-reference captures, which PHP cannot transport — fail fast at
     * registration with a clear message instead of dropping silently.
     *
     * @var array<int, string>
     */
    protected array $thenCallbacks = [];

    /**
     * Packed "catch" callbacks invoked if the job fails.
     *
     * @var array<int, string>
     */
    protected array $catchCallbacks = [];

    /**
     * Constructor.
     *
     * The queue contract is enforced here (not at destruction): a destructor
     * must never throw, so misconfiguration surfaces at the `queue()` call
     * site with a clear, catchable exception.
     *
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $payload Serialized job payload
     * @param array<string, mixed> $config Queue / dispatch configuration
     * @throws \InvalidArgumentException When Ai jobs would land outside a properly configured Ai queue
     */
    public function __construct(
        protected string $jobClass,
        protected array $payload = [],
        protected array $config = [],
    ) {
        $options = array_merge(static::queueConfig(), $this->config);
        AiQueue::assertConfigured(
            isset($options['config']) && is_string($options['config']) ? $options['config'] : null,
        );
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
     * @throws \InvalidArgumentException When the callback cannot be serialized for transport
     */
    public function addThen(Closure $callback): void
    {
        $this->thenCallbacks[] = self::packCallback($callback);
    }

    /**
     * Register a callback invoked if the job fails.
     *
     * @param \Closure $callback Failure callback
     * @throws \InvalidArgumentException When the callback cannot be serialized for transport
     */
    public function addCatch(Closure $callback): void
    {
        $this->catchCallbacks[] = self::packCallback($callback);
    }

    /**
     * Dispatch the job when the handle is destroyed.
     *
     * Destructors must not throw, so dispatch failures are logged and
     * swallowed. Note this intentionally does not fire "catch" callbacks:
     * those observe job failures, not dispatch failures.
     */
    public function __destruct()
    {
        $payload = $this->payload;

        if ($this->thenCallbacks !== []) {
            $payload['then'] = $this->thenCallbacks;
        }

        if ($this->catchCallbacks !== []) {
            $payload['catch'] = $this->catchCallbacks;
        }

        try {
            $options = array_merge(static::queueConfig(), $this->config);

            if (self::syncEnabled($this->jobClass, $payload, $options)) {
                $runner = SyncJobRunner::class;
                $runner::run($this->jobClass, $payload, $options);
            } else {
                QueueManager::push($this->jobClass, $payload, $options);
            }
        } catch (Throwable $throwable) {
            Log::warning(sprintf(
                'Pending dispatch of [%s] failed: %s',
                $this->jobClass,
                $throwable->getMessage(),
            ));
        }
    }

    /**
     * Pack a completion callback for queue transport.
     *
     * Mirrors `DispatchableTrait::pack()` for a `SerializableClosure` value:
     * closures can only cross the process boundary via PHP serialization.
     * Serializability is verified here (not at dispatch) so untransportable
     * callbacks fail fast with a clear message.
     *
     * @param \Closure $callback Response or failure callback
     * @return string Packed callback
     * @throws \InvalidArgumentException When the callback cannot be serialized
     */
    protected static function packCallback(Closure $callback): string
    {
        try {
            return base64_encode(serialize(new SerializableClosure($callback)));
        } catch (Throwable $throwable) {
            throw new InvalidArgumentException(
                'Queued completion callbacks must be serializable for transport: '
                . 'avoid by-reference captures and unserializable bound state.',
                0,
                $throwable,
            );
        }
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
