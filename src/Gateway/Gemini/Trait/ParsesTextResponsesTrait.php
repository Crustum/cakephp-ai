<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

use Cake\Utility\Text;
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
 * Parses Gemini generateContent responses.
 */
trait ParsesTextResponsesTrait
{
    use DecodesStructuredOutputTrait;

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
        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];

        $text = $this->extractText($parts);
        $rawToolCalls = $this->extractRawToolCalls($parts);

        return new StepResponse(
            text: $text,
            toolCalls: $this->mapToolCalls($rawToolCalls),
            finishReason: $this->extractFinishReason($data, $rawToolCalls),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model, $this->extractCitations($data)),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            providerContentBlocks: $this->sanitizeRequestParts($this->excludeThinkingParts($parts)),
        );
    }

    /**
     * Determine if a response part is a thinking/thought part.
     *
     * @param array<string, mixed> $part Response part
     * @return bool
     */
    protected function isThinkingPart(array $part): bool
    {
        return $part['thought'] ?? false;
    }

    /**
     * Sanitize functionCall parts so they can be sent back to Gemini as conversation history.
     *
     * @param array<int, array<string, mixed>> $parts Response parts
     * @return array<int, array<string, mixed>>
     */
    protected function sanitizeRequestParts(array $parts): array
    {
        return array_map(function (array $part): array {
            if (!isset($part['functionCall'])) {
                return $part;
            }

            $functionCall = ['name' => $part['functionCall']['name'] ?? ''];

            $args = $part['functionCall']['args'] ?? null;

            if (Value::filled($args)) {
                $functionCall['args'] = $args;
            }

            $part['functionCall'] = $functionCall;

            return $part;
        }, $parts);
    }

    /**
     * Filter out thinking parts from the response.
     *
     * @param array<int, array<string, mixed>> $parts Response parts
     * @return array<int, array<string, mixed>>
     */
    protected function excludeThinkingParts(array $parts): array
    {
        return array_values(array_filter(
            $parts,
            fn(array $part): bool => !$this->isThinkingPart($part),
        ));
    }

    /**
     * Extract the text content from the response parts.
     *
     * @param array<int, array<string, mixed>> $parts Response parts
     * @return string
     */
    protected function extractText(array $parts): string
    {
        $textParts = [];

        foreach ($parts as $part) {
            if (isset($part['text']) && !$this->isThinkingPart($part)) {
                $textParts[] = $part['text'];
            }
        }

        return implode('', $textParts);
    }

    /**
     * Extract raw tool calls from the response parts.
     *
     * @param array<int, array<string, mixed>> $parts Response parts
     * @return array<int, array<string, mixed>>
     */
    protected function extractRawToolCalls(array $parts): array
    {
        return array_values(
            array_map(
                fn(array $part): array => $part['functionCall'],
                array_filter($parts, fn(array $part): bool => isset($part['functionCall'])),
            ),
        );
    }

    /**
     * Map raw function call data to ToolCall DTOs.
     *
     * @param array<int, array<string, mixed>> $rawToolCalls Raw tool calls
     * @return array<int, \Crustum\Ai\Responses\Data\ToolCall>
     */
    protected function mapToolCalls(array $rawToolCalls): array
    {
        return array_map(function (array $fc): ToolCall {
            $id = $fc['id'] ?? Text::uuid();

            return new ToolCall(
                $id,
                $fc['name'] ?? '',
                $fc['args'] ?? [],
                $id,
            );
        }, $rawToolCalls);
    }

    /**
     * Extract citations from the response data.
     *
     * @param array<string, mixed> $data Response data
     * @return array<int, \Crustum\Ai\Responses\Data\UrlCitation>
     */
    protected function extractCitations(array $data): array
    {
        $citations = [];

        $candidate = $data['candidates'][0] ?? [];

        $sources = $candidate['citationMetadata']['citationSources'] ?? [];

        foreach ($sources as $source) {
            if (isset($source['uri'])) {
                $citations[] = new UrlCitation(
                    $source['uri'],
                    $source['title'] ?? null,
                );
            }
        }

        $groundingChunks = $candidate['groundingMetadata']['groundingChunks'] ?? [];
        $groundingSupports = $candidate['groundingMetadata']['groundingSupports'] ?? [];

        $referencedIndices = [];

        foreach ($groundingSupports as $support) {
            foreach ($support['groundingChunkIndices'] ?? [] as $index) {
                $referencedIndices[$index] = true;
            }
        }

        foreach (array_keys($referencedIndices) as $index) {
            $web = $groundingChunks[$index]['web'] ?? [];

            if (isset($web['uri'])) {
                $citations[] = new UrlCitation(
                    $web['uri'],
                    $web['title'] ?? null,
                );
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
     * Extract usage data from the response.
     *
     * @param array<string, mixed> $data Response data
     * @return \Crustum\Ai\Responses\Data\Usage
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usageMetadata'] ?? [];

        $promptTokens = $usage['promptTokenCount'] ?? 0;
        $cachedTokens = $usage['cachedContentTokenCount'] ?? 0;

        return new Usage(
            $promptTokens - $cachedTokens,
            $usage['candidatesTokenCount'] ?? 0,
            0,
            $cachedTokens,
            $usage['thoughtsTokenCount'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the Gemini response.
     *
     * @param array<string, mixed> $data Response data
     * @param array<int, array<string, mixed>> $rawToolCalls Raw tool calls
     * @return \Crustum\Ai\Responses\Data\FinishReason
     */
    protected function extractFinishReason(array $data, array $rawToolCalls): FinishReason
    {
        if (Value::filled($rawToolCalls)) {
            return FinishReason::ToolCalls;
        }

        $candidate = $data['candidates'][0] ?? [];
        $reason = $candidate['finishReason'] ?? '';

        return match ($reason) {
            'STOP' => FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'MALFORMED_FUNCTION_CALL' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}
