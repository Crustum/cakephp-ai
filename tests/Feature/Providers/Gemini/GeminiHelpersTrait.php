<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Feature\Providers\Gemini;

use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

trait GeminiHelpersTrait
{
    protected function fakeTextResponse(string $text = 'Hello'): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => $text]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
            'modelVersion' => 'gemini-3.7-flash',
        ]);
    }

    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', ?string $callId = null): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'id' => $callId ?? 'call_123',
                            'name' => $toolName,
                            'args' => (object)[],
                        ],
                    ]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
            'modelVersion' => 'gemini-3.7-flash',
        ]);
    }

    protected function fakeStructuredResponse(array $data): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => json_encode($data)]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
            'modelVersion' => 'gemini-3.7-flash',
        ]);
    }

    protected function fakeUniqueToolCallResponse(): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'id' => 'call_' . uniqid(),
                            'name' => 'FixedNumberGenerator',
                            'args' => (object)[],
                        ],
                    ]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
            'modelVersion' => 'gemini-3.7-flash',
        ]);
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent();

        $response = $agent->stream(
            'Hello',
            provider: 'gemini',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = 'data: ' . json_encode($event);
        }

        return implode("\n\n", $lines) . "\n\n";
    }

    protected function geminiChunk(array $parts, ?string $modelVersion = null, ?string $finishReason = null): array
    {
        $candidate = [
            'content' => [
                'parts' => $parts,
                'role' => 'model',
            ],
        ];

        if ($finishReason !== null) {
            $candidate['finishReason'] = $finishReason;
        }

        return [
            'candidates' => [$candidate],
            'modelVersion' => $modelVersion ?? 'gemini-3.7-flash',
        ];
    }

    protected function geminiChunkWithUsage(array $parts, int $promptTokens, int $candidatesTokens, int $cachedTokens = 0, ?string $modelVersion = null, string $finishReason = 'STOP'): array
    {
        $chunk = $this->geminiChunk($parts, $modelVersion, $finishReason);

        $chunk['usageMetadata'] = array_filter([
            'promptTokenCount' => $promptTokens,
            'candidatesTokenCount' => $candidatesTokens,
            'totalTokenCount' => $promptTokens + $candidatesTokens,
            'cachedContentTokenCount' => $cachedTokens ?: null,
        ]);

        return $chunk;
    }
}
