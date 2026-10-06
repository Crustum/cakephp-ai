<?php
declare(strict_types=1);

namespace Crustum\Ai\PendingResponses;

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\Question;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Event\ProviderFailedOver;
use Crustum\Ai\Exception\FailoverableException;
use Crustum\Ai\PendingResponses\Trait\ResolvesProviderOptionsTrait;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\ClassificationResponse;
use Crustum\Ai\Trait\ConditionableTrait;
use InvalidArgumentException;

/**
 * Pending classification request.
 *
 * Builder for configuring and executing classification requests.
 * Answers questions about a state using AI providers.
 */
class PendingClassification
{
    use ConditionableTrait;
    use ResolvesProviderOptionsTrait;

    /**
     * Questions to answer about the state.
     *
     * @var array<string, \Crustum\Ai\Contracts\Question>
     */
    protected array $questions = [];

    /**
     * Timeout in seconds for the classification request.
     */
    protected int $timeout = 30;

    /**
     * Create a new pending classification instance.
     *
     * @param array<string, mixed>|string $state The state to classify
     * @throws \InvalidArgumentException if the state is blank.
     */
    public function __construct(
        protected string|array $state,
    ) {
        if (blank($state)) {
            throw new InvalidArgumentException('A non-blank state is required to classify.');
        }
    }

    /**
     * Add a question to answer about the state.
     *
     * @param string $key Question key
     * @param \Crustum\Ai\Contracts\Question $question The question
     */
    public function question(string $key, Question $question): static
    {
        $this->questions[$key] = $question;

        return $this;
    }

    /**
     * Add questions to answer about the state.
     *
     * @param array<array-key, mixed> $questions Questions keyed by string
     * @throws \InvalidArgumentException if any entry is not a Question keyed by a string.
     */
    public function questions(array $questions): static
    {
        foreach ($questions as $key => $question) {
            if (!is_string($key) || !$question instanceof Question) {
                throw new InvalidArgumentException('Questions must be Question instances keyed by a string.');
            }

            $this->question($key, $question);
        }

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the classification request.
     *
     * @param int $seconds Timeout in seconds
     */
    public function timeout(int $seconds = 30): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Answer the questions about the state.
     *
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider or array of providers
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\ClassificationResponse
     * @throws \InvalidArgumentException if no questions were added.
     * @throws \Crustum\Ai\Exception\FailoverableException if every configured provider fails to classify the state.
     */
    public function classify(Lab|array|string|null $provider = null, ?string $model = null): ClassificationResponse
    {
        if ($this->questions === []) {
            throw new InvalidArgumentException('At least one question is required to classify.');
        }

        $providers = Provider::formatProviderAndModelList(
            $provider ?? Configure::read('Ai.default_for_classification'),
            $model,
        );

        $lastException = null;

        foreach ($providers as $provider => $model) {
            $provider = Ai::manager()->fakeableClassificationProvider($provider);

            [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($provider);

            $model ??= $provider->defaultClassificationModel();

            $provider = $provider->withHeaders($headers);

            try {
                return $provider->classify($this->state, $this->questions, $model, $this->timeout, $providerOptions);
            } catch (FailoverableException $e) {
                $lastException = $e;

                EventManager::instance()->dispatch(new ProviderFailedOver($provider->name(), $model, $e, $provider));

                continue;
            }
        }

        throw $lastException;
    }
}
