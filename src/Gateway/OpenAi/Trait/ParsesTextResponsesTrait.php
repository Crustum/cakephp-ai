<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAi\Trait;

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
 * Parses OpenAI Responses API text generation responses.
 */
trait ParsesTextResponsesTrait
{
    use DecodesStructuredOutputTrait;

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
            providerContentBlocks: $this->isStateless($provider) ? $this->extractReplayBlocks($output) : [],
        );
    }

    /**
     * Extract the ordered response output for stateless (store=false) replay.
     *
     * @param array<int, mixed> $output Response output
     * @return array<int, array<string, mixed>>
     */
    protected function extractReplayBlocks(array $output): array
    {
        return array_values(array_filter($output, is_array(...)));
    }

    /**
     * Serialize a tool result output value to a string.
     *
     * @param mixed $output Tool result output
     * @return string
     */
    protected function serializeToolResultOutput(mixed $output): string
    {
        return match (true) {
            is_string($output) => $output,
            is_array($output) => (string)json_encode($output),
            default => (string)$output,
        };
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
     * @return \Crustum\Ai\Responses\Data\Usage
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $cachedTokens = $usage['input_tokens_details']['cached_tokens'] ?? 0;
        $cacheWriteTokens = $usage['input_tokens_details']['cache_write_tokens'] ?? 0;

        return new Usage(
            $inputTokens - $cachedTokens - $cacheWriteTokens,
            $usage['output_tokens'] ?? 0,
            $cacheWriteTokens,
            $cachedTokens,
            $usage['output_tokens_details']['reasoning_tokens'] ?? 0,
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
