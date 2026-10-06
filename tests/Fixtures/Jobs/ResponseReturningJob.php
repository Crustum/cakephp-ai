<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Jobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use stdClass;

/**
 * Fixture job whose handler return is ignored.
 *
 * `JobInterface::execute()` is pinned to `?string`, so a response object can
 * never travel in the `execute()` return — it lives in `run()` (like the
 * real Ai jobs) while `execute()` returns `null`. Proves the processor
 * treats `null` as success (ACK) instead of failure + requeue.
 */
class ResponseReturningJob implements JobInterface
{
    /**
     * Last response produced by `run()`.
     */
    public mixed $response = null;

    /**
     * Execute the job from a queue message.
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string|null Null: the return is data-less, success is assumed
     */
    public function execute(Message $message): ?string
    {
        $this->response = $this->run($message->getArgument() ?? []);

        return null;
    }

    /**
     * Run the job from a decoded payload.
     *
     * @param array<string, mixed> $data Job payload
     * @return \stdClass Response object, never an ACK string
     */
    public function run(array $data): stdClass
    {
        $response = new stdClass();
        $response->value = is_array($data) && isset($data['value']) ? $data['value'] : 'response-object';

        return $response;
    }
}
