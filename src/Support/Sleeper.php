<?php
declare(strict_types=1);

namespace Crustum\Ai\Support;

use Cake\Chronos\Chronos;
use DateTimeInterface;

/**
 * Fakeable sleep helper.
 */
class Sleeper
{
    /**
     * Whether sleep calls are recorded instead of executed.
     */
    protected static bool $faked = false;

    /**
     * Recorded sleep durations in microseconds.
     *
     * @var list<int>
     */
    protected static array $sequence = [];

    /**
     * Callbacks invoked with the slept microseconds while faking.
     *
     * @var list<callable(int): void>
     */
    protected static array $fakeSleepCallbacks = [];

    /**
     * Keep Chronos' test "now" in sync when faking sleeps.
     */
    protected static bool $syncWithChronos = false;

    /**
     * Fake sleep calls so durations are recorded instead of executed.
     *
     * @param bool $value Whether to fake
     * @param bool $syncWithChronos Whether to advance Chronos' test now by each slept duration
     */
    public static function fake(bool $value = true, bool $syncWithChronos = false): void
    {
        static::$faked = $value;
        static::$sequence = [];
        static::$fakeSleepCallbacks = [];
        static::$syncWithChronos = $syncWithChronos;
    }

    /**
     * Reset the sleeper to real sleeps with an empty sequence.
     */
    public static function reset(): void
    {
        static::$faked = false;
        static::$sequence = [];
        static::$fakeSleepCallbacks = [];
        static::$syncWithChronos = false;
    }

    /**
     * Determine whether sleeps are currently faked.
     */
    public static function isFaked(): bool
    {
        return static::$faked;
    }

    /**
     * Recorded sleep durations in microseconds.
     *
     * @return list<int>
     */
    public static function sequence(): array
    {
        return static::$sequence;
    }

    /**
     * Start a pending sleep for the given amount in the unit of the
     * chained call (`seconds`, `milliseconds`, `microseconds`, `minutes`).
     *
     * @param float|int $amount Sleep amount
     */
    public static function for(float|int $amount): PendingSleep
    {
        return new PendingSleep($amount);
    }

    /**
     * Sleep until the given timestamp.
     *
     * @param \DateTimeInterface|string|float|int $timestamp Target timestamp (instance, epoch, or numeric string)
     */
    public static function until(DateTimeInterface|float|int|string $timestamp): PendingSleep
    {
        $target = $timestamp instanceof DateTimeInterface
            ? (float)$timestamp->format('U.u')
            : (float)$timestamp;

        $microseconds = (int)round(($target - (float)Chronos::now()->format('U.u')) * 1_000_000);

        return (new PendingSleep(max(0, $microseconds)))->microseconds();
    }

    /**
     * Sleep for the given duration in seconds.
     *
     * @param float|int $seconds Sleep duration in seconds
     */
    public static function sleep(float|int $seconds): PendingSleep
    {
        return static::for($seconds)->seconds();
    }

    /**
     * Sleep for the given duration in microseconds.
     *
     * @param int $microseconds Sleep duration in microseconds
     */
    public static function usleep(int $microseconds): PendingSleep
    {
        return static::for($microseconds)->microseconds();
    }

    /**
     * Sleep or record the given duration in microseconds.
     *
     * @param int $microseconds Sleep duration in microseconds
     * @internal Single execution entry point for pending sleeps.
     */
    public static function sleepMicroseconds(int $microseconds): bool
    {
        $microseconds = max(0, $microseconds);

        if (static::$faked) {
            static::$sequence[] = $microseconds;

            if (static::$syncWithChronos && Chronos::hasTestNow()) {
                $now = (float)Chronos::now()->format('U.u');

                Chronos::setTestNow(Chronos::createFromTimestamp($now + $microseconds / 1_000_000));
            }

            foreach (static::$fakeSleepCallbacks as $callback) {
                $callback($microseconds);
            }

            return true;
        }

        $remaining = $microseconds;

        $seconds = intdiv($remaining, 1_000_000);

        if ($seconds > 0) {
            sleep($seconds);
        }

        $remainder = $remaining % 1_000_000;

        if ($remainder > 0) {
            usleep($remainder);
        }

        return true;
    }

    /**
     * Specify a callback that should be invoked when faking sleep within a test.
     *
     * @param callable(int): void $callback Callback receiving the slept microseconds
     */
    public static function whenFakingSleep(callable $callback): void
    {
        static::$fakeSleepCallbacks[] = $callback;
    }

    /**
     * Indicate that Chronos' test "now" should be kept in sync when faking sleeps.
     */
    public static function syncWithChronos(bool $value = true): void
    {
        static::$syncWithChronos = $value;
    }
}
