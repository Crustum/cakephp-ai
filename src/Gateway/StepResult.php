<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Closure;
use Crustum\Ai\PendingStep;
use Generator;
use IteratorAggregate;
use Traversable;

/**
 * The result of a generation step passed through agent middleware.
 *
 * @implements \IteratorAggregate<int, \Crustum\Ai\Streaming\Event\StreamEvent>
 */
class StepResult implements IteratorAggregate
{
    /**
     * @var array<int, \Closure(\Crustum\Ai\Gateway\StepResponse): void>
     */
    protected array $callbacks = [];

    protected ?StepResponse $response = null;

    protected bool $resolved = false;

    /**
     * @var array<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    protected array $buffered = [];

    /**
     * Constructor.
     *
     * @param \Crustum\Ai\Gateway\StepResponse|\Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent, mixed, \Crustum\Ai\Gateway\StepResponse|null> $source Step stream or response
     * @param \Crustum\Ai\PendingStep|null $step Step as sent to the model; null when middleware supplied the response itself
     * @param \Crustum\Ai\Gateway\StepContext|null $context Step context
     * @param int|null $startedAt Monotonic start time in nanoseconds
     */
    public function __construct(
        protected Generator|StepResponse $source,
        public readonly ?PendingStep $step = null,
        public readonly ?StepContext $context = null,
        public readonly ?int $startedAt = null,
    ) {
    }

    /**
     * Register a callback to run once the step's response is available.
     *
     * @param \Closure(\Crustum\Ai\Gateway\StepResponse): void $callback Callback
     */
    public function then(Closure $callback): static
    {
        if (!$this->resolved) {
            $this->callbacks[] = $callback;
        } elseif ($this->response instanceof StepResponse) {
            $callback($this->response);
        }

        return $this;
    }

    /**
     * Iterate the stream to its end or call response(); a partial iteration cannot be resumed.
     *
     * @return \Traversable<int, \Crustum\Ai\Streaming\Event\StreamEvent>
     */
    public function getIterator(): Traversable
    {
        if ($this->source instanceof StepResponse) {
            if (!$this->resolved) {
                $this->resolve($this->source);
            }

            return;
        }

        yield from $this->buffered;

        if ($this->resolved) {
            $this->buffered = [];

            return;
        }

        while ($this->source->valid()) {
            $this->buffered[] = $event = $this->source->current();

            yield $event;

            $this->source->next();
        }

        $this->resolve($this->source->getReturn());
    }

    /**
     * Determine whether the step response is backed by a stream.
     *
     * @return bool
     */
    public function streamed(): bool
    {
        return $this->source instanceof Generator;
    }

    /**
     * The step's response, or null when a stream ended without one.
     *
     * @return \Crustum\Ai\Gateway\StepResponse|null
     */
    public function response(): ?StepResponse
    {
        if (!$this->resolved) {
            iterator_to_array($this, false);
        }

        return $this->response;
    }

    /**
     * Resolve the step response and invoke registered callbacks.
     *
     * @param mixed $response Step response
     */
    protected function resolve(mixed $response): void
    {
        $this->resolved = true;

        if (!$response instanceof StepResponse) {
            return;
        }

        $this->response = $response;

        foreach ($this->callbacks as $callback) {
            $callback($response);
        }
    }
}
