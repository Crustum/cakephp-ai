<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Ai;
use Crustum\Ai\Event\Classified;
use Crustum\Ai\Event\Classifying;
use Crustum\Ai\Prompts\ClassificationPrompt;
use Crustum\Ai\Responses\ClassificationResponse;

/**
 * Classifies states through the provider's classification gateway.
 */
trait ClassifiesTrait
{
    /**
     * Answer the given questions about the state.
     *
     * @param array<string, mixed>|string $state State to classify
     * @param array<string, \Crustum\Ai\Contracts\Question> $questions Questions to answer
     * @param string|null $model Model name
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
    ): ClassificationResponse {
        $invocationId = Text::uuid();

        $model ??= $this->defaultClassificationModel();

        $prompt = new ClassificationPrompt($state, $questions, $this, $model, $timeout, $providerOptions);

        if (Ai::manager()->classificationIsFaked()) {
            Ai::manager()->recordClassification($prompt);
        }

        $this->events->dispatch(new Classifying(
            $invocationId,
            $this,
            $model,
            $prompt,
        ));

        $response = $this->classificationGateway()->classify(
            $this,
            $model,
            $state,
            $questions,
            $timeout,
            $providerOptions,
        );

        $this->events->dispatch(new Classified(
            $invocationId,
            $this,
            $model,
            $prompt,
            $response,
        ));

        return $response;
    }
}
