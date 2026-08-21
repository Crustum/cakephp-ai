<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Xai\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\Trait\DecodesStructuredOutputTrait;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Responses\Data\Usage;

/**
 * Parses xAI Responses API text responses.
 */
trait ParsesTextResponsesTrait
{
    use DecodesStructuredOutputTrait;

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
        $lastOutput = end($output);

        if (is_array($lastOutput)) {
            return $lastOutput['content'][0]['text'] ?? '';
        }

        return '';
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
     * Extract usage data from the response.
     *
     * @param array<string, mixed> $data Response data
     * @return \Crustum\Ai\Responses\Data\Usage
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $cachedTokens = $usage['input_tokens_details']['cached_tokens'] ?? 0;

        return new Usage(
            $inputTokens - $cachedTokens,
            $usage['output_tokens'] ?? 0,
            0,
            $cachedTokens,
            $usage['output_tokens_details']['reasoning_tokens'] ?? 0,
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
        $output = $data['output'] ?? [];
        $lastOutput = end($output);
        $status = $lastOutput['status'] ?? $data['status'] ?? '';
        $type = $lastOutput['type'] ?? '';

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
                );
            }
        }

        return $toolCalls;
    }
}
