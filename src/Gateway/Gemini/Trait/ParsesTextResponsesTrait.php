<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\Trait\DecodesStructuredOutputTrait;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\ImageUsage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ProviderToolCall;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Trait\JoinsReasoningTrait;
use Crustum\Ai\Utility\Value;

/**
 * Parses Gemini Interactions API responses.
 */
trait ParsesTextResponsesTrait
{
    use DecodesStructuredOutputTrait;
    use JoinsReasoningTrait;

    /**
     * The step types Gemini derives from our own request rather than the model's turn.
     *
     * @var array<int, string>
     */
    private const REQUEST_STEP_TYPES = ['user_input', 'function_result'];

    /**
     * Validate the Gemini response data.
     *
     * @param array<string, mixed> $data Response data
     * @return void
     * @throws \Crustum\Ai\Exception\AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (!$data || isset($data['error'])) {
            throw new AiException(sprintf(
                'Gemini Error: [%s] %s',
                $data['error']['code'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown Gemini error.',
            ));
        }

        $errors = $data['errors'] ?? [];

        // A withheld answer is reported as a finish reason rather than thrown.
        if (in_array($data['status'] ?? '', ['failed', 'cancelled'], true) && !$this->wasBlocked($errors)) {
            throw new AiException(sprintf(
                'Gemini Error: [%s] %s',
                $errors[0]['code'] ?? $data['status'],
                $errors[0]['message'] ?? 'The Gemini interaction did not complete.',
            ));
        }
    }

    /**
     * Parse the Gemini response data into a single step response.
     *
     * @param array<string, mixed> $data Response data
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param bool $structured Whether structured output is active
     * @return \Crustum\Ai\Gateway\StepResponse
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        string $model,
        bool $structured,
    ): StepResponse {
        $steps = $data['steps'] ?? [];

        $text = $this->extractText($steps);
        $functionCallSteps = $this->extractFunctionCallSteps($steps);

        return new StepResponse(
            text: $text,
            toolCalls: $this->mapToolCalls($functionCallSteps, $this->thoughtSignature($steps)),
            finishReason: $this->extractFinishReason($data, $functionCallSteps),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model, $this->extractCitations($steps)),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            replayBlocks: $this->replayableSteps($steps),
            reasoning: $this->extractReasoning($steps),
            providerToolCalls: $this->extractProviderToolCalls($steps),
        );
    }

    /**
     * Get the steps that must be replayed verbatim on the next request.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     * @return array<int, array<string, mixed>>
     */
    protected function replayableSteps(array $steps): array
    {
        return array_values(array_filter(
            $steps,
            fn(array $step): bool => !in_array($step['type'] ?? '', self::REQUEST_STEP_TYPES, true),
        ));
    }

    /**
     * Extract the provider-executed tool steps, such as search and code execution.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     * @return array<int, \Crustum\Ai\Responses\Data\ProviderToolCall>
     */
    protected function extractProviderToolCalls(array $steps): array
    {
        return array_values(array_map(
            fn(array $step): ProviderToolCall => new ProviderToolCall(
                (string)($step['id'] ?? ''),
                (string)preg_replace('/_(call|result)$/', '', $step['type']),
                $step,
            ),
            array_filter($steps, fn(array $step): bool => $this->isProviderToolStep($step)),
        ));
    }

    /**
     * Determine if the given step was executed by Gemini rather than by us.
     *
     * @param array<string, mixed> $step Response step
     * @return bool
     */
    protected function isProviderToolStep(array $step): bool
    {
        $type = $step['type'] ?? '';

        return !in_array($type, ['function_call', 'function_result'], true)
            && preg_match('/_(call|result)$/', $type) === 1;
    }

    /**
     * Determine if a step carries the model's reasoning.
     *
     * @param array<string, mixed> $step Response step
     * @return bool
     */
    protected function isThinkingStep(array $step): bool
    {
        return ($step['type'] ?? '') === 'thought';
    }

    /**
     * Extract the reasoning text from the response steps.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     */
    protected function extractReasoning(array $steps): string
    {
        return static::joinReasoning(array_map(
            fn(array $step): string => $this->textOf($step),
            array_values(array_filter($steps, fn(array $step): bool => $this->isThinkingStep($step))),
        ));
    }

    /**
     * Extract the answer text from the model output steps.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     */
    protected function extractText(array $steps): string
    {
        return implode('', array_map(
            fn(array $step): string => $this->textOf($step),
            array_filter($steps, fn(array $step): bool => ($step['type'] ?? '') === 'model_output'),
        ));
    }

    /**
     * Concatenate the text blocks of the given step, which thought steps carry as a summary.
     *
     * @param array<string, mixed> $step Response step
     */
    protected function textOf(array $step): string
    {
        return implode('', array_map(
            fn(array $block): string => (string)($block['text'] ?? ''),
            array_filter(
                $step['summary'] ?? $step['content'] ?? [],
                fn(mixed $block): bool => is_array($block) && isset($block['text']),
            ),
        ));
    }

    /**
     * Extract the steps carrying function calls.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     * @return array<int, array<string, mixed>>
     */
    protected function extractFunctionCallSteps(array $steps): array
    {
        return array_values(
            array_filter($steps, fn(array $step): bool => ($step['type'] ?? '') === 'function_call'),
        );
    }

    /**
     * Map function call steps to ToolCall DTOs.
     *
     * @param array<int, array<string, mixed>> $functionCallSteps Function call steps
     * @param string|null $thoughtSignature Thought signature for the turn
     * @return array<int, \Crustum\Ai\Responses\Data\ToolCall>
     */
    protected function mapToolCalls(array $functionCallSteps, ?string $thoughtSignature = null): array
    {
        return array_map(function (array $step) use ($thoughtSignature): ToolCall {
            $id = $step['id'] ?? Text::uuid();

            return new ToolCall(
                $id,
                $step['name'] ?? '',
                $this->decodeArguments($step['arguments'] ?? []),
                $id,
                thoughtSignature: $thoughtSignature,
            );
        }, $functionCallSteps);
    }

    /**
     * Collect the thought signature Gemini attached to the turn's thinking.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     */
    protected function thoughtSignature(array $steps): ?string
    {
        $signatures = array_filter(array_map(
            fn(array $step): string => $this->isThinkingStep($step) ? (string)($step['signature'] ?? '') : '',
            $steps,
        ));

        return end($signatures) ?: null;
    }

    /**
     * Normalize function call arguments, which stream as a partial JSON string.
     *
     * @param mixed $arguments Arguments value
     * @return array<string, mixed>
     */
    protected function decodeArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        return is_string($arguments) && trim($arguments) !== ''
            ? (json_decode($arguments, true) ?: [])
            : [];
    }

    /**
     * Extract citations from the annotations Gemini attaches to its text blocks.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     * @return array<int, \Crustum\Ai\Responses\Data\UrlCitation>
     */
    protected function extractCitations(array $steps): array
    {
        $citations = [];

        foreach ($steps as $step) {
            // A stream parks its annotations on the step rather than on the text block they belong to.
            $annotations = array_merge($step['annotations'] ?? [], ...array_map(
                fn(mixed $block): array => is_array($block) ? ($block['annotations'] ?? []) : [],
                $step['content'] ?? [],
            ));

            foreach ($annotations as $annotation) {
                if (($annotation['type'] ?? '') === 'url_citation') {
                    $citations[] = new UrlCitation(
                        $annotation['url'] ?? '',
                        $annotation['title'] ?? null,
                    );
                }
            }
        }

        $unique = [];

        foreach ($citations as $citation) {
            $key = $citation->url;

            $unique[$key] ??= $citation;
        }

        return array_values($unique);
    }

    /**
     * Extract usage data from the response.
     *
     * @param array<string, mixed> $data Response data
     * @return \Crustum\Ai\Responses\Data\TextUsage
     */
    protected function extractUsage(array $data): TextUsage
    {
        $usage = $data['usage'] ?? [];

        $reasoningTokens = $usage['total_thought_tokens'] ?? null;

        // Gemini reports thought tokens outside the output token count.
        return new TextUsage(
            inputTokens: $usage['total_input_tokens'] ?? 0,
            outputTokens: ($usage['total_output_tokens'] ?? 0) + ($reasoningTokens ?? 0),
            cacheReadInputTokens: $usage['total_cached_tokens'] ?? null,
            reasoningTokens: $reasoningTokens,
        );
    }

    /**
     * Extract usage data from an image generation response.
     *
     * @param array<string, mixed> $data Response data
     * @return \Crustum\Ai\Responses\Data\ImageUsage
     */
    protected function extractImageUsage(array $data): ImageUsage
    {
        $usage = $data['usage'] ?? [];

        $text = $this->extractUsage($data);

        return new ImageUsage(
            $text->inputTokens,
            $text->outputTokens,
            $text->cacheReadInputTokens,
            $text->cacheWriteInputTokens,
            $text->reasoningTokens,
            $this->modalityTokens($usage['input_tokens_by_modality'] ?? [], 'IMAGE'),
            $this->modalityTokens($usage['output_tokens_by_modality'] ?? [], 'IMAGE'),
        );
    }

    /**
     * Get the token count Gemini reported for the given modality.
     *
     * @param array<int, array<string, mixed>> $details Token details
     * @param string $modality Modality
     * @return int|null
     */
    protected function modalityTokens(array $details, string $modality): ?int
    {
        foreach ($details as $detail) {
            if (strcasecmp((string)($detail['modality'] ?? ''), $modality) === 0) {
                return $detail['tokens'] ?? null;
            }
        }

        return null;
    }

    /**
     * Extract and map the finish reason from the Gemini response.
     *
     * @param array<string, mixed> $data Response data
     * @param array<int, array<string, mixed>> $functionCallSteps Function call steps
     * @return \Crustum\Ai\Responses\Data\FinishReason
     */
    protected function extractFinishReason(array $data, array $functionCallSteps): FinishReason
    {
        if (Value::filled($functionCallSteps)) {
            return FinishReason::ToolCalls;
        }

        if ($this->wasBlocked($data['errors'] ?? [])) {
            return FinishReason::ContentFilter;
        }

        return match ($data['status'] ?? '') {
            'completed' => FinishReason::Stop,
            'incomplete' => FinishReason::Length,
            'requires_action' => FinishReason::ToolCalls,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Determine if the interaction failed because Gemini withheld the content.
     *
     * @param array<int, array<string, mixed>> $errors Response errors
     */
    protected function wasBlocked(array $errors): bool
    {
        foreach ($errors as $error) {
            $haystack = strtolower(($error['code'] ?? '') . ' ' . ($error['message'] ?? ''));

            foreach (['safety', 'blocked', 'blocklist', 'prohibited', 'recitation'] as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
