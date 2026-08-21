<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

/**
 * Measures the wall time spent in a provider or tool call.
 */
trait MeasuresDurationTrait
{
    /**
     * Get the milliseconds elapsed since the given monotonic nanosecond reading.
     *
     * @param int $startedAt Monotonic nanosecond reading
     * @return float
     */
    protected function elapsedMilliseconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1e6;
    }
}
