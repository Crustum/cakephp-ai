<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\ProviderToolEvent;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Streaming\Event\ToolResult as ToolResultEvent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.xai', [

        ...(array)Configure::read('Ai.providers.xai'),
        'key' => 'test-key',
    ]);
});

test('streaming emits text events', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
                ['type' => 'response.output_text.delta', 'delta' => ' world'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    expect($events[0])->toBeInstanceOf(StreamStart::class)
        ->and($events[1])->toBeInstanceOf(TextStart::class)
        ->and($events[2])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and($events[3])->toBeInstanceOf(TextDelta::class)->delta->toBe(' world')
        ->and($events[4])->toBeInstanceOf(TextEnd::class)
        ->and($events[5])->toBeInstanceOf(StreamEnd::class);
});

test('streaming emits reasoning events', function (string $eventType): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => $eventType, 'delta' => 'Let me think...', 'item_id' => 'rs_1'],
                ['type' => 'response.output_item.done', 'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []]],
                ['type' => 'response.output_text.delta', 'delta' => 'Answer'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Answer']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 3]]]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $reasoningStarts = array_values(array_filter($events, fn($event): bool => $event instanceof ReasoningStart));
    $reasoningEnds = array_values(array_filter($events, fn($event): bool => $event instanceof ReasoningEnd));

    expect($reasoningStarts)->not->toBeEmpty()
        ->and($reasoningEnds)->not->toBeEmpty();

    $reasoningDelta = array_values(array_filter($events, fn($event): bool => $event instanceof ReasoningDelta))[0];

    expect($reasoningDelta->delta)->toBe('Let me think...');
})->with([
    'reasoning summary' => 'response.reasoning_summary_text.delta',
    'reasoning text' => 'response.reasoning_text.delta',
]);

test('streaming starts a new text part after each text end in the same step', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.output_text.delta', 'delta' => 'First'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.output_text.delta', 'delta' => 'Second'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'FirstSecond']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $textStarts = array_values(array_filter($events, fn($e): bool => $e instanceof TextStart));
    $textEnds = array_values(array_filter($events, fn($e): bool => $e instanceof TextEnd));
    $textDeltas = array_values(array_filter($events, fn($e): bool => $e instanceof TextDelta));

    expect($textStarts)->toHaveCount(2)
        ->and($textEnds)->toHaveCount(2)
        ->and($textDeltas)->toHaveCount(2)
        ->and($textStarts[0]->messageId)->not->toBe($textStarts[1]->messageId)
        ->and($textEnds[0]->messageId)->toBe($textStarts[0]->messageId)
        ->and($textEnds[1]->messageId)->toBe($textStarts[1]->messageId)
        ->and($textDeltas[0]->messageId)->toBe($textStarts[0]->messageId)
        ->and($textDeltas[1]->messageId)->toBe($textStarts[1]->messageId);
});

test('streaming handles tool calls', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator']],
                    ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{}'],
                    ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{}'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            aiHttpResponse(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_456', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'The number is 72019'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_456', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

    $toolCallEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent));
    $toolResultEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolResultEvent));

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolResultEvents)->not->toBeEmpty();
});

test('streaming tool loop emits a single stream end with accumulated usage', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator']],
                    ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{}'],
                    ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{}'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 2], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            aiHttpResponse(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_456', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'The number is 72019'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_456', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 8], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

    $streamEnds = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd));

    expect($streamEnds)->toHaveCount(1)
        ->and($streamEnds[0]->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnds[0]->usage->inputTokens)->toBe(30)
        ->and($streamEnds[0]->usage->outputTokens)->toBe(15)
        ->and($streamEnds[0]->usage->cacheReadInputTokens)->toBe(10);
});

test('streaming captures usage', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.output_text.delta', 'delta' => 'Hi'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 2], 'output_tokens_details' => ['reasoning_tokens' => 3]]]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->inputTokens)->toBe(10)
        ->and($streamEnd->usage->outputTokens)->toBe(8)
        ->and($streamEnd->usage->cacheReadInputTokens)->toBe(2)
        ->and($streamEnd->usage->reasoningTokens)->toBe(3);
});

test('streaming error event stops stream', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Internal server error']],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $error = null;

    try {
        $this->collectStreamEvents();
    } catch (StreamErrorException $streamErrorException) {
        $error = $streamErrorException->error;
    }

    expect($error)->toBeInstanceOf(Error::class)
        ->and($error->type)->toBe('server_error')
        ->and($error->message)->toBe('Internal server error');
});

test('streaming finish reason maps correctly', function (string $status, string $type, $expected): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => $status, 'output' => [['type' => $type, 'status' => $status, 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe($expected->value);
})->with([
    'completed message maps to Stop' => ['completed', 'message', FinishReason::Stop],
    'completed function_call maps to ToolCalls' => ['completed', 'function_call', FinishReason::ToolCalls],
    'incomplete maps to Length' => ['incomplete', 'message', FinishReason::Length],
    'failed maps to Error' => ['failed', 'message', FinishReason::Error],
    'unknown status maps to Unknown' => ['mystery_status', 'message', FinishReason::Unknown],
    'completed unknown type maps to Unknown' => ['completed', 'mystery_output', FinishReason::Unknown],
]);

test('streaming emits provider tool events for code interpreter code deltas', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.code_interpreter_call_code.delta', 'item_id' => 'ci_1', 'output_index' => 0, 'delta' => 'print(1)'],
                ['type' => 'response.code_interpreter_call_code.done', 'item_id' => 'ci_1', 'output_index' => 0, 'code' => 'print(1)'],
                ['type' => 'response.output_text.delta', 'delta' => '1'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '1']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $providerEvents = array_values(array_filter($this->collectStreamEvents(), fn($e): bool => $e instanceof ProviderToolEvent));

    expect(array_map(fn(ProviderToolEvent $e): string => $e->status, $providerEvents))->toBe(['code_delta', 'code_done'])
        ->and($providerEvents[0]->type)->toBe('code_interpreter_call')
        ->and($providerEvents[0]->itemId)->toBe('ci_1');
});
