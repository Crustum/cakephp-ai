<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Feature\Providers\Anthropic;

use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

trait AnthropicHelpersTrait
{
    protected function fakeTextResponse(string $text = 'Hello'): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    /**
     * Build a fake Anthropic response with the given content blocks.
     *
     * @param array<int, array<string, mixed>> $content Content blocks
     */
    protected function fakeThinkingResponse(array $content): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => $content,
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator'): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'msg_tool_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_123',
                'name' => $toolName,
                'input' => (object)[],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function fakeStructuredResponse(array $data): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'text',
                'text' => json_encode($data),
            ]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function fakeSyntheticStructuredResponse(array $data): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_123',
                'name' => 'output_structured_data',
                'input' => $data,
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function fakeUniqueToolCallResponse(): AiHttpResponseDefinition
    {
        return aiHttpResponse([
            'id' => 'msg_tool_' . uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_' . uniqid(),
                'name' => 'FixedNumberGenerator',
                'input' => (object)[],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent();

        $response = $agent->stream(
            'Hello',
            provider: 'anthropic',
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

    protected function messageStart(): array
    {
        return [
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_1',
                'model' => 'claude-sonnet-4-6',
                'role' => 'assistant',
                'content' => [],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
            ],
        ];
    }

    protected function contentBlockStart(int $index, array $contentBlock): array
    {
        return [
            'type' => 'content_block_start',
            'index' => $index,
            'content_block' => $contentBlock,
        ];
    }

    protected function contentBlockDelta(int $index, array $delta): array
    {
        return [
            'type' => 'content_block_delta',
            'index' => $index,
            'delta' => $delta,
        ];
    }

    protected function contentBlockStop(int $index): array
    {
        return [
            'type' => 'content_block_stop',
            'index' => $index,
        ];
    }

    protected function messageDelta(string $stopReason, int $outputTokens, ?int $thinkingTokens = null): array
    {
        $usage = ['output_tokens' => $outputTokens];

        if ($thinkingTokens !== null) {
            $usage['output_tokens_details'] = ['thinking_tokens' => $thinkingTokens];
        }

        return [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => $stopReason],
            'usage' => $usage,
        ];
    }
}
