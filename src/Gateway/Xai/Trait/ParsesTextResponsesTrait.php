<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Xai\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\Trait\DecodesStructuredOutputTrait;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ProviderToolCall;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Trait\JoinsReasoningTrait;

/**
 * Parses xAI Responses API text responses.
 */
trait ParsesTextResponsesTrait
{
    use DecodesStructuredOutputTrait;
    use JoinsReasoningTrait;

    /**
     * Validate the xAI response data.
     *
     * @param array<string, mixed> $data Response data
     * @return void
     * @throws \Crustum\Ai\Exception\AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (!$data || isset($data['error'])) {
            throw new AiException(sprintf(
                'xAI Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown xAI error.',
            ));
        }

        if (($data['status'] ?? '') === 'failed') {
            $error = $data['error'] ?? [];

            throw new AiException(sprintf(
                'xAI Error: [%s] %s',
                $error['code'] ?? 'unknown',
                $error['message'] ?? 'The response failed without an error message.',
            ));
        }
    }

    /**
     * Parse a single xAI response into a StepResponse.
     *
     * @param array<string, mixed> $data Response data
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param bool $structured Whether structured output is active
     * @return \Crustum\Ai\Gateway\StepResponse
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        $output = $data['output'] ?? [];
        $model = $data['model'] ?? '';

        $text = $this->extractText($output);
        $citations = $this->extractCitations($output);
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($data);

        $mappedToolCalls = $this->mapToolCallsWithReasoning($output);

        return new StepResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model, $citations),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            continuationToken: $data['id'] ?? null,
            reasoning: $this->extractReasoning($output),
            providerToolCalls: $this->extractProviderToolCalls($output),
        );
    }

    /**
     * Extract the text content from the output array.
     *
     * @param array<int, array<string, mixed>> $output Output items
     * @return string
     */
    protected function extractText(array $output): string
    {
        /** @var array<int, array<string, mixed>> $messages */
        $messages = collection($output)
            ->filter(fn(mixed $item): bool => is_array($item) && ($item['type'] ?? '') === 'message')
            ->toList();

        $message = end($messages);

        return is_array($message) ? ($message['content'][0]['text'] ?? '') : '';
    }

    /**
     * Extract citations from the output array.
     *
     * @param array<int, array<string, mixed>> $output Output items
     * @return array<int, \Crustum\Ai\Responses\Data\UrlCitation>
     */
    protected function extractCitations(array $output): array
    {
        $citations = [];

        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                foreach ($content['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? '') === 'url_citation') {
                        $citations[] = new UrlCitation(
                            $annotation['url'] ?? '',
                            $annotation['title'] ?? null,
                            isset($annotation['start_index']) ? (int)$annotation['start_index'] : null,
                            isset($annotation['end_index']) ? (int)$annotation['end_index'] : null,
                        );
                    }
                }
            }
        }

        return $citations;
    }

    /**
     * Extract the provider-hosted tool items from the output array.
     *
     * @param array<int, mixed> $output Output items
     * @return array<int, \Crustum\Ai\Responses\Data\ProviderToolCall>
     */
    protected function extractProviderToolCalls(array $output): array
    {
        return array_values(array_map(
            fn(array $item): ProviderToolCall => new ProviderToolCall($item['id'] ?? '', $item['type'], $item),
            array_filter($output, fn(mixed $item): bool => is_array($item)
                && ($item['type'] ?? '') !== 'function_call'
                && str_ends_with((string)($item['type'] ?? ''), '_call')),
        ));
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
        $reasoningTokens = $usage['output_tokens_details']['reasoning_tokens'] ?? null;

        return new TextUsage(
            inputTokens: $usage['input_tokens'] ?? 0,
            outputTokens: ($usage['output_tokens'] ?? 0) + ($reasoningTokens ?? 0),
            cacheReadInputTokens: $usage['input_tokens_details']['cached_tokens'] ?? null,
            reasoningTokens: $reasoningTokens,
        );
    }

    /**
     * Extract and map the finish reason from the xAI response.
     *
     * @param array<string, mixed> $data Response data
     * @return \Crustum\Ai\Responses\Data\FinishReason
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        /** @var array<int, array<string, mixed>> $output */
        $output = $data['output'] ?? [];

        /** @var array<int, array<string, mixed>> $items */
        $items = collection($output)
            ->filter(fn(mixed $item): bool => is_array($item) && ($item['type'] ?? '') !== 'reasoning')
            ->toList();

        $lastOutput = end($items);
        $status = is_array($lastOutput) ? ($lastOutput['status'] ?? $data['status'] ?? '') : ($data['status'] ?? '');
        $type = is_array($lastOutput) ? ($lastOutput['type'] ?? '') : '';

        return match ($status) {
            'incomplete' => FinishReason::Length,
            'failed' => FinishReason::Error,
            'completed' => match ($type) {
                'function_call' => FinishReason::ToolCalls,
                'message' => FinishReason::Stop,
                default => str_ends_with((string)$type, '_call') ? FinishReason::ToolCalls : FinishReason::Unknown,
            },
            default => FinishReason::Unknown,
        };
    }

    /**
     * Extract the reasoning text from the output array.
     *
     * @param array<int, array<string, mixed>> $output Output items
     */
    protected function extractReasoning(array $output): string
    {
        /** @var \Cake\Collection\CollectionInterface<int, string> $texts */
        $texts = collection($output)
            ->filter(fn(mixed $item): bool => is_array($item) && ($item['type'] ?? '') === 'reasoning')
            ->map(function (array $item): array {
                /** @var array<int, array<string, mixed>> $summary */
                $summary = $item['summary'] ?? [];
                /** @var array<int, array<string, mixed>> $content */
                $content = $item['content'] ?? [];
                /** @var \Cake\Collection\CollectionInterface<int, string> $summaryTexts */
                $summaryTexts = collection($summary)->map(fn(array $entry): string => $entry['text'] ?? '');
                /** @var \Cake\Collection\CollectionInterface<int, string> $contentTexts */
                $contentTexts = collection($content)->map(fn(array $entry): string => $entry['text'] ?? '');

                return [
                    implode('', $summaryTexts->toList()),
                    implode('', $contentTexts->toList()),
                ];
            })
            ->unfold();

        return static::joinReasoning($texts->toList());
    }

    /**
     * Map tool calls with their associated reasoning blocks.
     *
     * @param array<int, array<string, mixed>> $output Output items
     * @return array<int, \Crustum\Ai\Responses\Data\ToolCall>
     */
    protected function mapToolCallsWithReasoning(array $output): array
    {
        $toolCalls = [];
        $latestReasoning = null;

        foreach ($output as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'reasoning') {
                $latestReasoning = $item;

                continue;
            }

            if ($type === 'function_call') {
                $toolCalls[] = new ToolCall(
                    $item['id'] ?? '',
                    $item['name'] ?? '',
                    json_decode($item['arguments'] ?? '{}', true) ?? [],
                    $item['call_id'] ?? null,
                    $latestReasoning ? ($latestReasoning['id'] ?? null) : null,
                    $latestReasoning ? ($latestReasoning['summary'] ?? null) : null,
                );
            }
        }

        return $toolCalls;
    }
}
