<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use Crustum\Ai\Classification\Boolean;
use Crustum\Ai\Classification\Choice;
use Crustum\Ai\Classification\Score;
use Crustum\Ai\Contracts\Providers\ClassificationProvider;
use Crustum\Ai\Contracts\Question;
use Crustum\Ai\Responses\ClassificationResponse;
use Crustum\Ai\Responses\Data\Answer;
use Crustum\Ai\Responses\Data\BooleanAnswer;
use Crustum\Ai\Responses\Data\ChoiceAnswer;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ScoreAnswer;
use Crustum\Ai\Responses\Data\TextUsage;

/**
 * Answers classification questions through a decisions-style endpoint.
 */
trait AnswersQuestionsTrait
{
    /**
     * Get the path of the endpoint that answers questions.
     *
     * @return string
     */
    abstract protected function classificationEndpoint(): string;

    /**
     * Answer the given questions about the state.
     *
     * @param \Crustum\Ai\Contracts\Providers\ClassificationProvider $provider Classification provider
     * @param string $model Model name
     * @param array<string, mixed>|string $state State to classify
     * @param array<string, \Crustum\Ai\Contracts\Question> $questions Questions to answer
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn() => $this->client($provider, $timeout)->post($this->classificationEndpoint(), array_merge($providerOptions, [
                'model' => $model,
                'state' => $state,
                'questions' => array_map($this->mapQuestion(...), $questions),
            ])),
        );

        $data = $response->getJson() ?? [];

        $answers = [];

        foreach ($data['answers'] ?? [] as $key => $answer) {
            $mapped = $this->mapAnswer($answer, $questions[$key] ?? null);

            if ($mapped instanceof Answer) {
                $answers[$key] = $mapped;
            }
        }

        return new ClassificationResponse(
            $answers,
            new TextUsage(
                inputTokens: $data['usage']['input_tokens'] ?? 0,
                outputTokens: $data['usage']['output_tokens'] ?? 0,
            ),
            new Meta($provider->name(), $this->answeringModel($data, $model)),
        );
    }

    /**
     * Get the name of the model that answered the questions.
     *
     * @param array<string, mixed> $data Response data
     * @param string $model Model name
     * @return string
     */
    protected function answeringModel(array $data, string $model): string
    {
        return $data['model'] ?? $model;
    }

    /**
     * Map a question to the decisions wire format.
     *
     * @param \Crustum\Ai\Contracts\Question $question Question to map
     * @return array<string, mixed>
     */
    protected function mapQuestion(Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => array_filter([
                'type' => 'noul',
                'instructions' => $question->instructions,
                'criteria' => $question->criteria,
            ], fn($value): bool => $value !== null),
            $question instanceof Choice => [
                'type' => 'choice',
                'instructions' => $question->instructions,
                'criteria' => $question->options,
            ],
            $question instanceof Score => [
                'type' => 'score',
                'instructions' => $question->instructions,
                'criteria' => $question->levels,
            ],
            default => $question->toArray(),
        };
    }

    /**
     * Map a decisions answer to an answer object, skipping unknown answer types.
     *
     * @param array<string, mixed> $answer Answer payload
     * @param \Crustum\Ai\Contracts\Question|null $question Question that was asked
     * @return \Crustum\Ai\Responses\Data\Answer|null
     */
    protected function mapAnswer(array $answer, ?Question $question = null): ?Answer
    {
        return match ($answer['type'] ?? null) {
            'noul' => new BooleanAnswer($answer['noul']),
            'choice' => new ChoiceAnswer($answer['choice'], $answer['probabilities'] ?? [], $answer['confidence'] ?? null),
            'score' => new ScoreAnswer(
                $answer['score'],
                $this->withIntegerKeys($answer['probabilities'] ?? []),
                $this->withIntegerKeys($answer['legend'] ?? ($question instanceof Score ? $question->levels : [])),
                $answer['confidence'] ?? null,
            ),
            default => null,
        };
    }

    /**
     * Cast the string level keys the provider returns to integers.
     *
     * @param array<int|string, mixed> $levels Level map with string keys
     * @return array<int, mixed>
     */
    protected function withIntegerKeys(array $levels): array
    {
        $result = [];

        foreach ($levels as $level => $value) {
            $result[(int)$level] = $value;
        }

        ksort($result);

        return $result;
    }
}
