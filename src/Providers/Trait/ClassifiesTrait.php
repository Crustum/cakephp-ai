<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Ai;
use Crustum\Ai\Event\ClassificationFailed;
use Crustum\Ai\Event\Classified;
use Crustum\Ai\Event\Classifying;
use Crustum\Ai\Prompts\ClassificationPrompt;
use Crustum\Ai\Responses\ClassificationResponse;
use LogicException;
use Throwable;

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
     * @param array<int|string, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile> $attachments Attachments to classify alongside the state
     * @return \Crustum\Ai\Responses\ClassificationResponse
     */
    public function classify(
        string|array $state,
        array $questions,
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
    ): ClassificationResponse {
        if ($attachments !== [] && !$this->supportsClassificationAttachments()) {
            throw new LogicException("Provider [{$this->name()}] does not support classification attachments.");
        }

        $invocationId = Text::uuid();

        $model ??= $this->defaultClassificationModel();

        $prompt = new ClassificationPrompt($state, $questions, $this, $model, $timeout, $providerOptions, $attachments);

        if (Ai::manager()->classificationIsFaked()) {
            Ai::manager()->recordClassification($prompt);
        }

        $this->events->dispatch(new Classifying(
            $invocationId,
            $this,
            $model,
            $prompt,
        ));

        $response = $this->dispatchingFailure(
            fn(): ClassificationResponse => $this->classificationGateway()->classify(
                $this,
                $model,
                $state,
                $questions,
                $timeout,
                $providerOptions,
                $attachments,
            ),
            fn(Throwable $exception): ClassificationFailed => new ClassificationFailed($invocationId, $this, $model, $prompt, $exception),
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

    /**
     * Determine if the provider can classify attachments alongside the state.
     *
     * @return bool
     */
    public function supportsClassificationAttachments(): bool
    {
        return false;
    }
}
