<?php
declare(strict_types=1);

namespace Crustum\Ai\Support;

use Closure;
use RuntimeException;

/**
 * Pending sleep duration.
 *
 * Accumulates the amounts passed to `Sleeper::for()` / `and()` in the unit
 * of each chained call and sleeps once the instance is destroyed or `then()`
 * is invoked.
 */
class PendingSleep
{
    /**
     * Accumulated sleep duration in microseconds.
     */
    protected int $microseconds = 0;

    /**
     * Pending amount awaiting its unit call.
     *
     * @var float|int|null
     */
    protected float|int|null $pending = null;

    /**
     * Callback determining whether sleeping should continue.
     *
     * @var \Closure(): bool|null
     */
    protected ?Closure $while = null;

    /**
     * Whether the instance should sleep.
     */
    protected bool $shouldSleep = true;

    /**
     * Whether the instance already slept via `then()`.
     */
    protected bool $alreadySlept = false;

    /**
     * Constructor.
     *
     * @param float|int $amount Sleep amount in the chained unit
     */
    public function __construct(float|int $amount)
    {
        $this->pending = $amount;
    }

    /**
     * Sleep for the pending amount in seconds.
     */
    public function seconds(): static
    {
        $this->microseconds += (int)round($this->pullPending() * 1_000_000);

        return $this;
    }

    /**
     * Sleep for the pending amount in seconds.
     */
    public function second(): static
    {
        return $this->seconds();
    }

    /**
     * Sleep for the pending amount in minutes.
     */
    public function minutes(): static
    {
        $this->microseconds += (int)round($this->pullPending() * 60_000_000);

        return $this;
    }

    /**
     * Sleep for the pending amount in minutes.
     */
    public function minute(): static
    {
        return $this->minutes();
    }

    /**
     * Sleep for the pending amount in milliseconds.
     */
    public function milliseconds(): static
    {
        $this->microseconds += (int)round($this->pullPending() * 1_000);

        return $this;
    }

    /**
     * Sleep for the pending amount in milliseconds.
     */
    public function millisecond(): static
    {
        return $this->milliseconds();
    }

    /**
     * Sleep for the pending amount in microseconds.
     */
    public function microseconds(): static
    {
        $this->microseconds += (int)round($this->pullPending());

        return $this;
    }

    /**
     * Sleep for the pending amount in microseconds.
     */
    public function microsecond(): static
    {
        return $this->microseconds();
    }

    /**
     * Add additional time to sleep for, consumed by the next unit call.
     *
     * @param float|int $amount Additional sleep amount
     */
    public function and(float|int $amount): static
    {
        $this->pending = $amount;

        return $this;
    }

    /**
     * Sleep while the given callback returns true.
     *
     * The callback never runs while faked — the whole duration is recorded
     * as a single entry instead.
     *
     * @param \Closure(): bool $callback Continuation callback
     */
    public function while(Closure $callback): static
    {
        $this->while = $callback;

        return $this;
    }

    /**
     * Only sleep when the given condition is true.
     *
     * @param \Closure(static): bool|bool $condition Condition value or callback
     */
    public function when(bool|Closure $condition): static
    {
        $this->shouldSleep = $condition instanceof Closure ? (bool)$condition($this) : $condition;

        return $this;
    }

    /**
     * Don't sleep when the given condition is true.
     *
     * @param \Closure(static): bool|bool $condition Condition value or callback
     */
    public function unless(bool|Closure $condition): static
    {
        return $this->when($condition instanceof Closure ? !$condition($this) : !$condition);
    }

    /**
     * Sleep, then execute the given callback.
     *
     * @param callable $then Callback executed after sleeping
     * @return mixed Callback result
     */
    public function then(callable $then): mixed
    {
        $this->goodnight();

        $this->alreadySlept = true;

        return $then();
    }

    /**
     * Handle the object's destruction.
     */
    public function __destruct()
    {
        $this->goodnight();
    }

    /**
     * Sleep unless the instance opted out, then record or execute once.
     *
     * While faked, the whole duration is recorded as a single entry and the
     * `while` callback never runs.
     *
     * @throws \RuntimeException When a unit call is missing its amount
     */
    protected function goodnight(): void
    {
        if ($this->alreadySlept || !$this->shouldSleep) {
            return;
        }

        if ($this->pending !== null) {
            throw new RuntimeException('Unknown duration unit.');
        }

        if (Sleeper::isFaked()) {
            Sleeper::sleepMicroseconds($this->microseconds);

            return;
        }

        $while = $this->while ?? function (): bool {
            static $run = true;

            if (!$run) {
                return false;
            }

            $run = false;

            return true;
        };

        $remaining = $this->microseconds;

        while ($while()) {
            $seconds = intdiv($remaining, 1_000_000);

            if ($seconds > 0) {
                Sleeper::sleepMicroseconds($seconds * 1_000_000);

                $remaining -= $seconds * 1_000_000;
            }

            if ($remaining > 0) {
                Sleeper::sleepMicroseconds($remaining);
            }
        }
    }

    /**
     * Resolve the pending amount, consuming it.
     *
     * @return float|int
     * @throws \RuntimeException When no amount was specified
     */
    protected function pullPending(): float|int
    {
        if ($this->pending === null) {
            $this->shouldSleep = false;

            throw new RuntimeException('No duration specified.');
        }

        $pending = $this->pending < 0 ? 0 : $this->pending;

        $this->pending = null;

        return $pending;
    }
}
