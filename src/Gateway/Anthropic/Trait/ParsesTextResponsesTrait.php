<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Anthropic\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\Trait\DecodesStructuredOutputTrait;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\UrlCitation;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Utility\Value;

/**
 * Parses Anthropic Messages API text responses.
 */
trait ParsesTextResponsesTrait
{
    use DecodesStructuredOutputTrait;

    /**
     * Validate the Anthropic response data.
     *
     * @param array<string, mixed> $data Response data
     * @return void
     * @throws \Crustum\Ai\Exception\AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (!$data || ($data['type'] ?? '') === 'error') {
            throw new AiException(sprintf(
                'Anthropic Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown Anthropic error.',
            ));
        }
    }

    /**
     * Parse the Anthropic response data into a single step response.
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
        return $this->buildStepResponse(
            $data['content'] ?? [],
            $provider,
            $data['model'] ?? '',
            $this->extractUsage($data),
            $this->extractFinishReason($data),
            $structured,
        );
    }

    /**
     * Build a single step response from Anthropic content blocks.
     *
     * @param array<int, array<string, mixed>> $content Content blocks
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param \Crustum\Ai\Responses\Data\Usage $usage Usage data
     * @param \Crustum\Ai\Responses\Data\FinishReason $finishReason Finish reason
     * @param bool $structured Whether structured output is active
     * @return \Crustum\Ai\Gateway\StepResponse
     */
    protected function buildStepResponse(
        array $content,
        Provider $provider,
        string $model,
        Usage $usage,
        FinishReason $finishReason,
        bool $structured,
    ): StepResponse {
        $text = $this->extractText($content);
        $toolCalls = $this->extractToolCalls($content);
        $citations = $this->extractCitations($content);

        $realToolCalls = array_values(array_filter($toolCalls, fn(ToolCall $tc): bool => $tc->name !== 'output_structured_data'));
        $hasStructuredToolCall = count($realToolCalls) < count($toolCalls);

        $structuredData = null;

        if ($structured || $hasStructuredToolCall) {
            $structuredData = $this->extractStructuredOutput($content);

            if (empty($structuredData) && Value::filled($text)) {
                $structuredData = $this->decodeStructuredOutput($text);
            }
        }

        if ($finishReason === FinishReason::ToolCalls && $realToolCalls === []) {
            $finishReason = FinishReason::Stop;
        }

        return new StepResponse(
            text: $hasStructuredToolCall && !empty($structuredData) ? (json_encode($structuredData) ?: '') : $text,
            toolCalls: $realToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model, $citations),
            structured: $structuredData,
            providerContentBlocks: $content,
        );
    }

    /**
     * Extract the text content from Anthropic content blocks.
     *
     * @param array<int, array<string, mixed>> $content Content blocks
     * @return string
     */
    protected function extractText(array $content): string
    {
        $textBlocks = array_filter($content, fn(array $block): bool => ($block['type'] ?? '') === 'text');

        return implode('', array_column($textBlocks, 'text'));
    }

    /**
     * Extract tool calls from Anthropic content blocks.
     *
     * @param array<int, array<string, mixed>> $content Content blocks
     * @return array<int, \Crustum\Ai\Responses\Data\ToolCall>
     */
    protected function extractToolCalls(array $content): array
    {
        $toolUseBlocks = array_filter($content, fn(array $block): bool => ($block['type'] ?? '') === 'tool_use');

        return array_values(array_map(fn(array $block): ToolCall => new ToolCall(
            $block['id'] ?? '',
            $block['name'] ?? '',
            $block['input'] ?? [],
            $block['id'] ?? null,
        ), $toolUseBlocks));
    }

    /**
     * Extract citations from Anthropic content blocks.
     *
     * @param array<int, array<string, mixed>> $content Content blocks
     * @return array<int, \Crustum\Ai\Responses\Data\UrlCitation>
     */
    protected function extractCitations(array $content): array
    {
        $citations = [];

        foreach ($content as $block) {
            $blockType = $block['type'] ?? '';

            if ($blockType === 'web_search_tool_result') {
                foreach ($block['search_results'] ?? [] as $result) {
                    $citations[] = new UrlCitation(
                        $result['url'] ?? '',
                        $result['title'] ?? null,
                    );
                }
            }

            if ($blockType === 'web_fetch_tool_result') {
                $result = $block['content'] ?? [];

                if (($result['type'] ?? '') === 'web_fetch_result' && filled($result['url'] ?? null)) {
                    $citations[] = new UrlCitation(
                        $result['url'],
                        $result['content']['title'] ?? null,
                    );
                }
            }

            if ($blockType === 'text') {
                foreach ($block['citations'] ?? [] as $citation) {
                    if (($citation['type'] ?? '') === 'web_search_result_location') {
                        $citations[] = new UrlCitation(
                            $citation['url'] ?? '',
                            $citation['title'] ?? null,
                        );
                    }
                }
            }
        }

        $unique = [];

        foreach ($citations as $citation) {
            $key = $citation->url;

            if (!isset($unique[$key])) {
                $unique[$key] = $citation;
            }
        }

        return array_values($unique);
    }

    /**
     * Extract usage data from the Anthropic response.
     *
     * @param array<string, mixed> $data Response data
     * @return \Crustum\Ai\Responses\Data\Usage
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];

        return new Usage(
            $usage['input_tokens'] ?? 0,
            $usage['output_tokens'] ?? 0,
            $usage['cache_creation_input_tokens'] ?? 0,
            $usage['cache_read_input_tokens'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the Anthropic response.
     *
     * @param array<string, mixed> $data Response data
     * @return \Crustum\Ai\Responses\Data\FinishReason
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        return match ($data['stop_reason'] ?? '') {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'pause_turn' => FinishReason::Continue,
            'max_tokens', 'model_context_window_exceeded' => FinishReason::Length,
            'refusal' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Extract structured output from the synthetic tool call.
     *
     * @param array<int, array<string, mixed>> $content Content blocks
     * @return array<string, mixed>
     */
    protected function extractStructuredOutput(array $content): array
    {
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'output_structured_data') {
                return $block['input'] ?? [];
            }
        }

        return [];
    }
}
