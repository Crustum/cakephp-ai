<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAiCompatible\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\Trait\DecodesStructuredOutputTrait;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\Usage;

/**
 * Parses OpenAI-compatible Chat Completions text generation responses.
 */
trait ParsesTextResponsesTrait
{
    use DecodesStructuredOutputTrait;

    /**
     * Validate the OpenAI-compatible response data.
     *
     * @param array<string, mixed> $data Response data
     * @return void
     * @throws \Crustum\Ai\Exception\AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (!$data || isset($data['error'])) {
            throw new AiException(sprintf(
                'OpenAI-compatible Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown error.',
            ));
        }
    }

    /**
     * Parse a single OpenAI-compatible response into a StepResponse.
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
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $message['content'] ?? '';
        $rawToolCalls = $message['tool_calls'] ?? [];

        $mappedToolCalls = array_map(
            fn(array $toolCall): ToolCall => new ToolCall(
                $toolCall['id'] ?? '',
                $toolCall['function']['name'] ?? '',
                json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
                $toolCall['id'] ?? null,
            ),
            $rawToolCalls,
        );

        return new StepResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $this->extractFinishReason($choice),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
        );
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
        $promptDetails = $usage['prompt_tokens_details'] ?? [];
        $completionDetails = $usage['completion_tokens_details'] ?? [];

        return new Usage(
            promptTokens: $usage['prompt_tokens'] ?? 0,
            completionTokens: $usage['completion_tokens'] ?? 0,
            cacheReadInputTokens: $promptDetails['cached_tokens'] ?? 0,
            reasoningTokens: $completionDetails['reasoning_tokens'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the response.
     *
     * @param array<string, mixed> $choice Choice payload
     * @return \Crustum\Ai\Responses\Data\FinishReason
     */
    protected function extractFinishReason(array $choice): FinishReason
    {
        return match ($choice['finish_reason'] ?? '') {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}
