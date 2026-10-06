<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Jobs;

use Cake\Queue\Job\Message;
use Crustum\Ai\Job\AiJobInterface;
use Crustum\Ai\Job\DispatchableTrait;
use Interop\Queue\Processor;

/**
 * Fixture Ai job that cannot be built without arguments.
 *
 * Mirrors the production incident: a constructor the container cannot
 * resolve must REJECT the message instead of killing the worker.
 */
class UnbuildableJob implements AiJobInterface
{
    use DispatchableTrait;

    /**
     * @param object|string|null $payload Unresolvable union payload
     */
    public function __construct(
        public object|string|null $payload,
    ) {
    }

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
        return 'unreachable';
    }
}
