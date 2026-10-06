<?php
declare(strict_types=1);

namespace Crustum\Ai\Job;

use Cake\Queue\Job\JobInterface;

/**
 * Contract for Ai queue jobs.
 *
 * Jobs are stateless: the worker (or sync runner) builds them with no
 * constructor arguments through `AiJobProcessor`, and all job data travels in
 * the message payload. `run()` contains only business logic — it returns the
 * produced response or throws. The full lifecycle (completion callbacks,
 * failure hook, ACK mapping) is owned by `Crustum\Ai\Queue\AiJobProcessor`.
 *
 * Optional convention (checked via `method_exists`, Laravel-style): a job may
 * define `failed(\Throwable $exception, array $data = []): void` to translate
 * a worker-side failure into domain effects (e.g. broadcast a failure event
 * built from the message payload). The processor invokes it; it is never
 * called by userland.
 */
interface AiJobInterface extends JobInterface
{
    /**
     * Run the job from a decoded payload.
     *
     * @param array<string, mixed> $data Job payload
     * @return mixed The produced response
     */
    public function run(array $data): mixed;

    /**
     * Get the response produced by the last `run()`.
     *
     * @return mixed The produced response, or null when the job has not run yet
     */
    public function getResponse(): mixed;
}
