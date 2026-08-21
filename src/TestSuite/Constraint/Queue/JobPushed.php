<?php
declare(strict_types=1);

namespace Crustum\Ai\TestSuite\Constraint\Queue;

use Cake\Queue\TestSuite\TestQueueClient;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Asserts a job was pushed onto the queue.
 *
 * @internal
 */
class JobPushed extends Constraint
{
    /**
     * @param string $jobClass Job class
     */
    public function __construct(protected string $jobClass)
    {
    }

    /**
     * @param mixed $other Unused
     * @return bool
     */
    protected function matches(mixed $other): bool
    {
        return TestQueueClient::getQueuedJobsByClass($this->jobClass) !== [];
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('job [%s] was pushed onto the queue', $this->jobClass);
    }

    /**
     * @param mixed $other Evaluated value
     * @return string
     */
    protected function failureDescription(mixed $other): string
    {
        return $this->toString() . "\n" . self::queuedJobsTimeline();
    }

    /**
     * Build a summary of the captured queued jobs for assertion failures.
     *
     * @return string
     */
    protected static function queuedJobsTimeline(): string
    {
        $jobs = TestQueueClient::getQueuedJobs();

        if ($jobs === []) {
            return 'Captured (0): none';
        }

        $lines = ['Captured (' . count($jobs) . '):'];

        foreach ($jobs as $job) {
            $lines[] = '  [' . ($job['jobClass'] ?? '?') . ']';
        }

        return implode("\n", $lines);
    }
}
