<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\TextGenerationOptions;

/**
 * Dispatched before a generation step is sent to the provider.
 */
class StartingStep extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Run invocation identifier
     * @param int $stepNumber Step index
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Provider instance
     * @param string $model Model the step is requested against
     * @param bool $isFinalStep Whether this is the final step
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Messages being sent for this step
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Resolved options for this step
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public array $messages,
        public ?TextGenerationOptions $options,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'stepNumber' => $stepNumber,
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'isFinalStep' => $isFinalStep,
            'messages' => $messages,
            'options' => $options,
        ]);
    }
}
