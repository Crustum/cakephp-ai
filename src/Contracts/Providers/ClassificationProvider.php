<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Providers;

use Crustum\Ai\Contracts\Gateway\ClassificationGateway;
use Crustum\Ai\Responses\ClassificationResponse;

/**
 * Classification Provider Interface
 *
 * Defines contract for providers that support classification capabilities.
 */
interface ClassificationProvider extends Provider
{
    /**
     * Answer the given questions about the state.
     *
     * @param array<string, mixed>|string $state State to classify
     * @param array<string, \Crustum\Ai\Contracts\Question> $questions Questions to answer about the state
     * @param string|null $model Model to use for classification
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\ClassificationResponse
     */
    public function classify(
        string|array $state,
        array $questions,
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): ClassificationResponse;

    /**
     * Get the provider's classification gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ClassificationGateway
     */
    public function classificationGateway(): ClassificationGateway;

    /**
     * Set the provider's classification gateway.
     *
     * @param \Crustum\Ai\Contracts\Gateway\ClassificationGateway $gateway Classification gateway
     * @return $this
     */
    public function useClassificationGateway(ClassificationGateway $gateway);

    /**
     * Get the name of the default classification model.
     *
     * @return string
     */
    public function defaultClassificationModel(): string;
}
