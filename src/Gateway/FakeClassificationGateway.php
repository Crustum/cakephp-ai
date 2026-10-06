<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Closure;
use Crustum\Ai\Classification\Boolean;
use Crustum\Ai\Classification\Choice;
use Crustum\Ai\Classification\Score;
use Crustum\Ai\Contracts\Gateway\ClassificationGateway;
use Crustum\Ai\Contracts\Providers\ClassificationProvider;
use Crustum\Ai\Contracts\Question;
use Crustum\Ai\Prompts\ClassificationPrompt;
use Crustum\Ai\Responses\ClassificationResponse;
use Crustum\Ai\Responses\Data\Answer;
use Crustum\Ai\Responses\Data\BooleanAnswer;
use Crustum\Ai\Responses\Data\ChoiceAnswer;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ScoreAnswer;
use Crustum\Ai\Responses\Data\TextUsage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fake Classification Gateway
 *
 * Fake implementation of classification gateway for testing purposes.
 * Allows simulating classification operations without making actual API calls.
 */
class FakeClassificationGateway implements ClassificationGateway
{
    /**
     * Current response index for array responses
     */
    protected int $currentResponseIndex = 0;

    /**
     * Whether to prevent classifications without fake responses
     */
    protected bool $preventStrayClassifications = false;

    /**
     * Constructor.
     *
     * @param \Closure|array $responses Responses to return
     */
    public function __construct(protected Closure|array $responses = [])
    {
    }

    /**
     * Answer the given questions about the state.
     *
     * @param \Crustum\Ai\Contracts\Providers\ClassificationProvider $provider The classification provider instance
     * @param string $model The model to use for classification
     * @param array<string, mixed>|string $state The state to classify
     * @param array<string, \Crustum\Ai\Contracts\Question> $questions The questions to answer
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
    ): ClassificationResponse {
        $prompt = new ClassificationPrompt($state, $questions, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $prompt);
    }

    /**
     * Get the next response instance.
     *
     * @param \Crustum\Ai\Contracts\Providers\ClassificationProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\ClassificationPrompt $prompt The classification prompt
     * @return \Crustum\Ai\Responses\ClassificationResponse
     */
    protected function nextResponse(
        ClassificationProvider $provider,
        string $model,
        ClassificationPrompt $prompt,
    ): ClassificationResponse {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt);

        $result = $this->marshalResponse($response, $provider, $model, $prompt);
        $this->currentResponseIndex++;

        return $result;
    }

    /**
     * Marshal the given response into a full response instance.
     *
     * @param mixed $response The response to marshal
     * @param \Crustum\Ai\Contracts\Providers\ClassificationProvider $provider The provider
     * @param string $model The model
     * @param \Crustum\Ai\Prompts\ClassificationPrompt $prompt The classification prompt
     * @return \Crustum\Ai\Responses\ClassificationResponse
     */
    protected function marshalResponse(
        mixed $response,
        ClassificationProvider $provider,
        string $model,
        ClassificationPrompt $prompt,
    ): ClassificationResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayClassifications) {
                throw new RuntimeException('Attempted classification without a fake response.');
            }

            $response = [];
        }

        if ($response instanceof ClassificationResponse) {
            return $response;
        }

        $answers = array_map(
            fn(Question $question, string $key): Answer => $response[$key] ?? $this->generateFakeAnswer($question),
            $prompt->questions,
            array_keys($prompt->questions),
        );

        return new ClassificationResponse(
            array_combine(array_keys($prompt->questions), $answers),
            new TextUsage(),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate a shape-valid fake answer for the given question.
     *
     * @param \Crustum\Ai\Contracts\Question $question The question
     * @return \Crustum\Ai\Responses\Data\Answer
     */
    protected function generateFakeAnswer(Question $question): Answer
    {
        return match (true) {
            $question instanceof Boolean => new BooleanAnswer(round(mt_rand() / mt_getrandmax(), 3)),
            $question instanceof Choice => $this->fakeChoiceAnswer(array_keys($question->options)),
            $question instanceof Score => $this->fakeScoreAnswer($question->levels),
            default => throw new InvalidArgumentException('Unsupported question [' . $question::class . '] for fake classification.'),
        };
    }

    /**
     * Generate a fake choice answer for the given options.
     *
     * @param list<string> $options The choice options
     * @return \Crustum\Ai\Responses\Data\ChoiceAnswer
     */
    protected function fakeChoiceAnswer(array $options): ChoiceAnswer
    {
        $probabilities = $this->randomDistribution($options);

        return new ChoiceAnswer(
            array_search(max($probabilities), $probabilities, true),
            $probabilities,
            round(max($probabilities), 3),
        );
    }

    /**
     * Generate a fake score answer for the given levels.
     *
     * @param list<string|array<string, mixed>> $levels The score levels
     * @return \Crustum\Ai\Responses\Data\ScoreAnswer
     */
    protected function fakeScoreAnswer(array $levels): ScoreAnswer
    {
        $probabilities = $this->randomDistribution(array_keys($levels));

        $score = array_sum(array_map(fn(int $level, float $p): float => $level * $p, array_keys($probabilities), $probabilities));

        return new ScoreAnswer(round($score, 3), $probabilities, $levels, round(max($probabilities), 3));
    }

    /**
     * Generate random probabilities summing to one over the given keys.
     *
     * @param list<int|string> $keys The keys to distribute probabilities over
     * @return array<int|string, float>
     */
    protected function randomDistribution(array $keys): array
    {
        $weights = array_map(fn(): int => mt_rand(1, 100), $keys);

        $total = array_sum($weights);

        return array_combine($keys, array_map(fn(int $weight): float => round($weight / $total, 3), $weights));
    }

    /**
     * Indicate that an exception should be thrown if any classification is not faked.
     *
     * @param bool $prevent Whether to prevent stray classifications
     */
    public function preventStrayClassifications(bool $prevent = true): static
    {
        $this->preventStrayClassifications = $prevent;

        return $this;
    }
}
