<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Jobs;

use Cake\Queue\Job\Message;
use Crustum\Ai\Job\AiJobInterface;
use Crustum\Ai\Job\DispatchableTrait;
use Interop\Queue\Processor;
use RuntimeException;
use Throwable;

/**
 * Fixture Ai job that always fails.
 */
class FailingJob implements AiJobInterface
{
    use DispatchableTrait;

    /**
     * Recorded `failed()` hook invocations.
     *
     * @var array<int, array{exception: \Throwable, data: array<string, mixed>}>
     */
    public static array $failedCalls = [];

    /**
     * Reset recorded hook invocations.
     */
    public static function reset(): void
    {
        static::$failedCalls = [];
    }

    /**
     * Handle a job failure (records the invocation for assertions).
     *
     * @param \Throwable $exception The exception
     * @param array<string, mixed> $data Job payload
     */
    public function failed(Throwable $exception, array $data = []): void
    {
        static::$failedCalls[] = ['exception' => $exception, 'data' => $data];
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
        throw new RuntimeException('job failed');
    }
}
