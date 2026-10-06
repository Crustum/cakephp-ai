<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAi\Trait;

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

/**
 * Parses OpenAI Responses API text generation responses.
 */
trait ParsesTextResponsesTrait
{
    use DecodesStructuredOutputTrait;
    use JoinsReasoningTrait;

    /**
     * Validate the OpenAI response data.
     *
     * @param array<string, mixed> $data Response data
     * @return void
     * @throws \Crustum\Ai\Exception\AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (!$data || isset($data['error'])) {
            throw new AiException(sprintf(
                'OpenAI Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown OpenAI error.',
            ));
        }

        if (($data['status'] ?? '') === 'failed') {
            $error = $data['error'] ?? [];

            throw new AiException(sprintf(
                'OpenAI Error: [%s] %s',
                $error['code'] ?? 'unknown',
                $error['message'] ?? 'The response failed without an error message.',
            ));
        }
    }

    /**
     * Parse the OpenAI response data into a single step response.
     *
     * @param array<string, mixed> $data Response data
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param bool $structured Whether structured output was requested
     * @return \Crustum\Ai\Gateway\StepResponse
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        $output = $data['output'] ?? [];
        $text = $this->extractText($output);

        return new StepResponse(
            text: $text,
            toolCalls: $this->mapToolCallsWithReasoning($output),
            finishReason: $this->extractFinishReason($data),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $data['model'] ?? '', $this->extractCitations($output)),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            continuationToken: $data['id'] ?? '',
            replayBlocks: $this->extractReplayBlocks($output),
            reasoning: $this->extractReasoning($output),
            providerToolCalls: $this->extractProviderToolCalls($output),
        );
    }

    /**
     * Extract the ordered response output for full-history replay.
     *
     * @param array<int, mixed> $output Response output
     * @return array<int, array<string, mixed>>
     */
    protected function extractReplayBlocks(array $output): array
    {
        return array_values(array_filter($output, is_array(...)));
    }

    /**
     * Extract the provider-hosted tool items from the output array.
     *
     * @param array<int, mixed> $output Response output
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
     * Extract the text content from the output array.
     *
     * @param array<int, array<string, mixed>> $output Output items
     * @return string
     */
    protected function extractText(array $output): string
    {
        $lastOutput = end($output);

        return is_array($lastOutput) ? ($lastOutput['content'][0]['text'] ?? '') : '';
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
                    if (($annotation['type'] ?? '') !== 'url_citation') {
                        continue;
                    }

                    $citations[] = new UrlCitation(
                        $annotation['url'] ?? '',
                        $annotation['title'] ?? null,
                        isset($annotation['start_index']) ? (int)$annotation['start_index'] : null,
                        isset($annotation['end_index']) ? (int)$annotation['end_index'] : null,
                    );
                }
            }
        }

        return $citations;
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

        return new TextUsage(
            inputTokens: $usage['input_tokens'] ?? 0,
            outputTokens: $usage['output_tokens'] ?? 0,
            cacheReadInputTokens: $usage['input_tokens_details']['cached_tokens'] ?? null,
            cacheWriteInputTokens: $usage['input_tokens_details']['cache_write_tokens'] ?? null,
            reasoningTokens: $usage['output_tokens_details']['reasoning_tokens'] ?? null,
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

        return new ImageUsage(
            inputTokens: $usage['input_tokens'] ?? 0,
            outputTokens: $usage['output_tokens'] ?? 0,
            cacheReadInputTokens: $usage['input_tokens_details']['cached_tokens'] ?? null,
            imageInputTokens: $usage['input_tokens_details']['image_tokens'] ?? null,
            imageOutputTokens: $usage['output_tokens_details']['image_tokens'] ?? null,
        );
    }

    /**
     * Extract and map the finish reason from the response.
     *
     * @param array<string, mixed> $data Response data
     * @return \Crustum\Ai\Responses\Data\FinishReason
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        $output = $data['output'] ?? [];
        $lastOutput = $output !== [] ? end($output) : false;
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
                    $latestReasoning ? ($latestReasoning['encrypted_content'] ?? null) : null,
                );
            }
        }

        return $toolCalls;
    }
}
