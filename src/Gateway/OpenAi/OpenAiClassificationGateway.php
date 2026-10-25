<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAi;

use Crustum\Ai\Classification\Boolean;
use Crustum\Ai\Classification\Choice;
use Crustum\Ai\Classification\Score;
use Crustum\Ai\Contracts\Gateway\ClassificationGateway;
use Crustum\Ai\Contracts\Providers\ClassificationProvider;
use Crustum\Ai\Contracts\Question;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Files\File;
use Crustum\Ai\Gateway\OpenAi\Trait\CreatesOpenAiClientTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ResolvesInlineImagesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\ClassificationResponse;
use Crustum\Ai\Responses\Data\Answer;
use Crustum\Ai\Responses\Data\BooleanAnswer;
use Crustum\Ai\Responses\Data\ChoiceAnswer;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ScoreAnswer;
use Crustum\Ai\Responses\Data\TextUsage;
use Laminas\Diactoros\UploadedFile;

/**
 * OpenAI Decisions classification gateway.
 *
 * Answers questions about a state through the OpenAI decisions endpoint,
 * optionally with image attachments sent inline as data URLs.
 */
class OpenAiClassificationGateway implements ClassificationGateway
{
    use CreatesOpenAiClientTrait;
    use HandlesFailoverErrorsTrait;
    use ResolvesInlineImagesTrait;

    /**
     * Answer the given questions about the state.
     *
     * @param \Crustum\Ai\Contracts\Providers\ClassificationProvider $provider Classification provider
     * @param string $model Model name
     * @param array<string, mixed>|string $state State to classify
     * @param array<string, \Crustum\Ai\Contracts\Question> $questions Questions to answer
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @param array<int|string, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile> $attachments Attachments to classify alongside the state
     * @return \Crustum\Ai\Responses\ClassificationResponse
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
    ): ClassificationResponse {
        $input = $this->mapInput($state, $attachments);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('decisions', array_merge($providerOptions, [
                'model' => $model,
                'input' => $input,
                'questions' => array_map($this->mapQuestion(...), array_keys($questions), $questions),
            ])),
        );

        $data = $response->getJson() ?? [];

        $answers = [];

        foreach ($data['answers'] ?? [] as $answer) {
            $key = $answer['name'] ?? null;

            if (!is_string($key)) {
                continue;
            }

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
            new Meta($provider->name(), $data['model'] ?? $model),
        );
    }

    /**
     * Map the state and attachments to the decisions input.
     *
     * @param array<string, mixed>|string $state State to classify
     * @param array<int|string, \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile> $attachments Attachments to classify alongside the state
     * @return array<int, array<string, mixed>>|string
     */
    protected function mapInput(string|array $state, array $attachments): string|array
    {
        $text = $this->toText($state);

        if ($attachments === []) {
            return $text;
        }

        $content = [['type' => 'input_text', 'text' => $text]];

        foreach ($attachments as $name => $attachment) {
            if (is_string($name)) {
                $content[] = ['type' => 'input_text', 'text' => "{$name}:"];
            }

            $content[] = $this->mapImage($attachment);
        }

        return [['role' => 'user', 'content' => $content]];
    }

    /**
     * Map an image to an input image part, which the decisions endpoint only accepts as an inline data URL.
     *
     * @param \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile $attachment Image attachment
     * @return array<string, mixed>
     * @throws \InvalidArgumentException if the attachment is not a JPEG, PNG, GIF, or WebP image with inline content.
     */
    protected function mapImage(File|UploadedFile $attachment): array
    {
        [$image, $content, $mime] = $this->resolveInlineImage($attachment, 'OpenAI decisions', [
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'image/gif' => 'GIF',
            'image/webp' => 'WebP',
        ]);

        return array_merge($image->providerOptions(Lab::OpenAI), [
            'type' => 'input_image',
            'image_url' => 'data:' . $mime . ';base64,' . base64_encode($content),
        ]);
    }

    /**
     * Map a question to the OpenAI decisions wire format.
     *
     * @param string $name Question name
     * @param \Crustum\Ai\Contracts\Question $question Question to map
     * @return array<string, mixed>
     */
    protected function mapQuestion(string $name, Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => [
                'type' => 'predicate',
                'name' => $name,
                'instructions' => $this->predicateInstructions($question),
            ],
            $question instanceof Choice => [
                'type' => 'choice',
                'name' => $name,
                'instructions' => $this->toText($question->instructions),
                'choices' => array_map(fn(string $value, $description): array => array_filter([
                    'value' => $value,
                    'description' => is_null($description) ? null : $this->toText($description),
                ], fn($value): bool => $value !== null), array_keys($question->options), $question->options),
            ],
            $question instanceof Score => [
                'type' => 'score',
                'name' => $name,
                'instructions' => $this->toText($question->instructions),
                'levels' => array_map($this->mapLevel(...), $question->levels),
            ],
            default => ['name' => $name, ...$question->toArray()],
        };
    }

    /**
     * Map a score level to a label, keeping the description of levels given as a label and description.
     *
     * @param array<string, mixed>|string $level Score level
     * @return array<string, mixed>
     */
    protected function mapLevel(string|array $level): array
    {
        if (is_string($level) || !is_string($level['label'] ?? null)) {
            return ['label' => $this->toText($level)];
        }

        return array_filter([
            'label' => $level['label'],
            'description' => isset($level['description']) ? $this->toText($level['description']) : null,
        ], fn($value): bool => $value !== null);
    }

    /**
     * Get the predicate instructions, folding in the true and false criteria the endpoint has no field for.
     *
     * @param \Crustum\Ai\Classification\Boolean $question Boolean question
     * @return string
     */
    protected function predicateInstructions(Boolean $question): string
    {
        $instructions = $this->toText($question->instructions);

        foreach ($question->criteria ?? [] as $case => $description) {
            $instructions .= "\n\n" . ucfirst($case) . ": {$description}";
        }

        return $instructions;
    }

    /**
     * Map an answer to an answer object, skipping refusals and unknown answer types.
     *
     * @param array<string, mixed> $answer Answer payload
     * @param \Crustum\Ai\Contracts\Question|null $question Question that was asked
     * @return \Crustum\Ai\Responses\Data\Answer|null
     */
    protected function mapAnswer(array $answer, ?Question $question): ?Answer
    {
        $probabilities = array_column($answer['probabilities'] ?? [], 'probability', 'value');

        return match ($answer['type'] ?? null) {
            'predicate' => new BooleanAnswer($answer['probability']),
            'choice' => new ChoiceAnswer((string)$answer['choice'], $probabilities, $answer['confidence'] ?? null),
            'score' => new ScoreAnswer(
                $answer['score'],
                $probabilities,
                $question instanceof Score ? $question->levels : array_column($answer['probabilities'] ?? [], 'label', 'value'),
                $answer['confidence'] ?? null,
            ),
            default => null,
        };
    }

    /**
     * Convert structured content to the text the endpoint accepts.
     *
     * @param array<string, mixed>|string $value Value to convert
     * @return string
     */
    protected function toText(string|array $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
