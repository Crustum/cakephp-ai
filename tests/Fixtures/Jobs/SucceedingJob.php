<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Jobs;

use Cake\Queue\Job\Message;
use Crustum\Ai\Job\AiJobInterface;
use Crustum\Ai\Job\DispatchableTrait;
use Interop\Queue\Processor;

/**
 * Fixture Ai job that always succeeds.
 */
class SucceedingJob implements AiJobInterface
{
    use DispatchableTrait;

    /**
     * Execute the job from a queue message.
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        $this->response = $this->run($message->getArgument() ?? []);

        return Processor::ACK;
    }

    /**
     * Run the job from a decoded payload.
     *
     * @param array<string, mixed> $data Job payload
     * @return mixed
     */
    public function run(array $data): mixed
    {
        return $data['value'] ?? 'job-response';
    }
}
