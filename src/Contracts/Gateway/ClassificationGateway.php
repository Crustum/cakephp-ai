<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Gateway;

use Crustum\Ai\Contracts\Providers\ClassificationProvider;
use Crustum\Ai\Responses\ClassificationResponse;

/**
 * Classification Gateway Interface
 *
 * Defines methods for answering questions about a given state.
 */
interface ClassificationGateway
{
    /**
     * Answer the given questions about the state.
     *
     * @param \Crustum\Ai\Contracts\Providers\ClassificationProvider $provider The classification provider instance
     * @param string $model The model to use for classification
     * @param array<string, mixed>|string $state The state to classify
     * @param array<string, \Crustum\Ai\Contracts\Question> $questions Questions to answer about the state
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\ClassificationResponse
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
    ): ClassificationResponse;
}
