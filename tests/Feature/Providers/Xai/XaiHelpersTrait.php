<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Feature\Providers\Xai;

use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

trait XaiHelpersTrait
{
    protected function fakeTextResponse(string $text = 'Hello'): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [
                [
                    'type' => 'message',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => $text, 'annotations' => []],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ]);
    }

    /**
     * Build a fake xAI text response with reasoning items.
     *
     * @param array<int, array<string, mixed>> $reasoningItems Reasoning items
     * @param string $text Response text
     * @param bool $reasoningFirst Whether reasoning items come before the message
     */
    protected function fakeReasonedTextResponse(array $reasoningItems, string $text = 'Answer', bool $reasoningFirst = false): AiHttpResponseDefinition
    {
        $message = [
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [
                ['type' => 'output_text', 'text' => $text, 'annotations' => []],
            ],
        ];

        return aiHttpResponse([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => $reasoningFirst ? [...$reasoningItems, $message] : [$message, ...$reasoningItems],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 3],
            ],
        ]);
    }

    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', ?string $callId = null): AiHttpResponseDefinition
    {
        $callId ??= 'call_123';

        return aiHttpResponse([
            'id' => 'resp_tool_' . uniqid(),
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [
                [
                    'type' => 'function_call',
                    'id' => 'fc_' . uniqid(),
                    'call_id' => $callId,
                    'name' => $toolName,
                    'arguments' => '{}',
                    'status' => 'completed',
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ]);
    }

    protected function fakeStructuredResponse(string $json = '{"symbol": "Au"}'): AiHttpResponseDefinition
    {
        return $this->fakeTextResponse($json);
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent();

        $response = $agent->stream('Hello', provider: 'xai');

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

        $lines[] = 'data: [DONE]';

        return implode("\n\n", $lines) . "\n\n";
    }
}
