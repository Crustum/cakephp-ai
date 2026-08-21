<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Queue;

use Cake\Queue\TestSuite\TestQueueClient;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts no job (optionally for a class) was pushed onto the queue.
 *
 * @internal
 */
class NoJobPushed extends Constraint
{
    /**
     * @param string|null $jobClass Job class, or null to assert none were pushed
     */
    public function __construct(protected ?string $jobClass = null)
    {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        $jobs = $this->jobClass === null
            ? TestQueueClient::getQueuedJobs()
            : TestQueueClient::getQueuedJobsByClass($this->jobClass);

        return $jobs === [];
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return $this->jobClass === null
            ? 'no job was pushed onto the queue'
            : sprintf('no job [%s] was pushed onto the queue', $this->jobClass);
    }
}
