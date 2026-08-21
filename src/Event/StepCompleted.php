<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\StepResponse;

/**
 * Dispatched after a generation step returns a response.
 */
class StepCompleted extends AiEvent
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
     * @param \Crustum\Ai\Gateway\StepResponse $response Step response
     * @param float $time Wall time spent in the provider call, in milliseconds
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public StepResponse $response,
        public float $time,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'stepNumber' => $stepNumber,
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'isFinalStep' => $isFinalStep,
            'response' => $response,
            'time' => $time,
        ]);
    }
}
