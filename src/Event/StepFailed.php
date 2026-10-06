<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Throwable;

/**
 * Dispatched after a generation step ends without producing a response.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Contracts\Agent>
 */
class StepFailed extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Run invocation identifier
     * @param int $stepNumber Step index
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Provider instance
     * @param string $model Model the step was requested against
     * @param bool $isFinalStep Whether this is the final step
     * @param \Throwable $exception The failure
     * @param float $time Wall time spent in the provider call before it failed, in milliseconds
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public Throwable $exception,
        public float $time,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'stepNumber' => $stepNumber,
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'isFinalStep' => $isFinalStep,
            'exception' => $exception,
            'time' => $time,
        ], $agent);
    }
}
