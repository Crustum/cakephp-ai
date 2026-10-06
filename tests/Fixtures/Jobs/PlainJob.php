<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Jobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Interop\Queue\Processor;

/**
 * Fixture job without any job-layer contract for generic lifecycle assertions.
 */
class PlainJob implements JobInterface
{
    /**
     * Execute the job from a queue message.
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        return Processor::ACK;
    }
}
