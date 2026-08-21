<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

/**
 * Step Context
 *
 * Represents the context for a step in multi-step text generation.
 * Used to track state and enable continuation tokens for stateful providers.
 */
class StepContext
{
    /**
     * Constructor
     *
     * @param int $stepNumber The current step number in the sequence.
     * @param bool $isFinalStep Whether this is the final step.
     * @param string|null $continuationToken Provider handle for stateful continuation; null for stateless providers that replay full history.
     */
    public function __construct(
        public readonly int $stepNumber = 0,
        public readonly bool $isFinalStep = false,
        public readonly ?string $continuationToken = null,
    ) {
    }
}
