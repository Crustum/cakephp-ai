<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Queue;

use Cake\Queue\TestSuite\TestQueueClient;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts a job was pushed onto the queue a specific number of times.
 *
 * @internal
 */
class JobPushedTimes extends Constraint
{
    /**
     * @param string $jobClass Job class
     * @param int $times Expected push count
     */
    public function __construct(
        protected string $jobClass,
        protected int $times,
    ) {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        return count(TestQueueClient::getQueuedJobsByClass($this->jobClass)) === $this->times;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('job [%s] was pushed %d time(s)', $this->jobClass, $this->times);
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        $actual = count(TestQueueClient::getQueuedJobsByClass($this->jobClass));

        return $this->toString() . ' but was pushed ' . $actual . ' time(s)';
    }
}
