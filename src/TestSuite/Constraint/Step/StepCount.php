<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Step;

use Crustum\Ai\TestSuite\Capture\EventCapture;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts the recorded step count.
 *
 * @internal
 */
class StepCount extends Constraint
{
    /**
     * @param mixed $other Expected count
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        return count(EventCapture::steps()) === (int)$other;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return 'agent step count matches expected value';
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return sprintf(
            'agent step count is %d matching expected %d',
            count(EventCapture::steps()),
            (int)$other,
        ) . "\n" . EventCapture::timeline();
    }
}
