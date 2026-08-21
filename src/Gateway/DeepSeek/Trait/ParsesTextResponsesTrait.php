<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\DeepSeek\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\Trait\DecodesStructuredOutputTrait;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Utility\Value;

/**
 * Parses DeepSeek Chat Completions text responses.
 */
trait ParsesTextResponsesTrait
{
    use DecodesStructuredOutputTrait;

    /**
     * Validate the DeepSeek response data.
     *
     * @param array<string, mixed> $data Response data
     * @return void
     * @throws \Crustum\Ai\Exception\AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (!$data || isset($data['error'])) {
            throw new AiException(sprintf(
                'DeepSeek Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown DeepSeek error.',
            ));
        }
    }

    /**
     * Parse the DeepSeek response data into a single step response.
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
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $message['content'] ?? '';
        $rawToolCalls = $message['tool_calls'] ?? [];

        $mappedToolCalls = array_map(fn(array $toolCall): ToolCall => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['function']['name'] ?? '',
            json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), $rawToolCalls);

        $providerContentBlocks = [];

        if (Value::filled($message['reasoning_content'] ?? null)) {
            $providerContentBlocks['reasoning_content'] = $message['reasoning_content'];
        }

        return new StepResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $this->extractFinishReason($choice),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            providerContentBlocks: $providerContentBlocks,
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
        $details = $usage['completion_tokens_details'] ?? [];

        return new Usage(
            promptTokens: ($usage['prompt_tokens'] ?? 0) - ($usage['prompt_cache_hit_tokens'] ?? 0),
            completionTokens: $usage['completion_tokens'] ?? 0,
            cacheReadInputTokens: $usage['prompt_cache_hit_tokens'] ?? 0,
            reasoningTokens: $details['reasoning_tokens'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the response.
     *
     * @param array<string, mixed> $choice Choice data
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
