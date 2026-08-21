<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('streaming emits text events', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDelta(' world'),
                $this->outputTextDone('Hello world'),
                $this->responseCompleted(10, 5),
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
        ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);
});

test('streaming starts a new text part after each text end in the same step', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('First'),
                $this->outputTextDone('First'),
                $this->outputTextDelta('Second'),
                $this->outputTextDone('Second'),
                $this->responseCompleted(10, 5, output: [
                    [
                        'type' => 'message',
                        'status' => 'completed',
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'First'],
                            ['type' => 'output_text', 'text' => 'Second'],
                        ],
                    ],
                ]),
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
        'api.openai.com/*' => aiHttpSequence([
            aiHttpResponse(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputItemAdded('fc_1', 'call_1', 'FixedNumberGenerator'),
                    $this->functionCallArgumentsDelta('fc_1', '{}'),
                    $this->functionCallArgumentsDone('fc_1', '{}'),
                    $this->responseCompleted(10, 5, output: [
                        ['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                    ]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            aiHttpResponse(
                body: $this->ssePayload([
                    [
                        'type' => 'response.created',
                        'response' => ['id' => 'resp_2', 'model' => 'gpt-5.4', 'status' => 'in_progress', 'output' => []],
                    ],
                    $this->outputTextDelta('The number is 72019'),
                    $this->outputTextDone('The number is 72019'),
                    $this->responseCompleted(20, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

    $toolCallEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent));
    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolCallEvents[0]->toolCall->resultId)->toBe('call_1')
        ->and($streamEnd->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnd->usage->promptTokens)->toBe(30)
        ->and($streamEnd->usage->completionTokens)->toBe(15);
});

test('streaming handles reasoning events', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Let me think...', 'item_id' => 'rs_1'],
                [
                    'type' => 'response.output_item.done',
                    'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Let me think...']]],
                ],
                $this->outputTextDelta('Answer'),
                $this->outputTextDone('Answer'),
                $this->responseCompleted(10, 15),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $types = array_map(fn($e) => $e::class, $events);

    expect($types)->toContain(ReasoningStart::class)
        ->toContain(ReasoningDelta::class)
        ->toContain(ReasoningEnd::class);

    $reasoningDelta = array_values(array_filter($events, fn($e): bool => $e instanceof ReasoningDelta))[0];
    expect($reasoningDelta->delta)->toBe('Let me think...');
});

test('streaming error event stops stream', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                ['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Server overloaded']],
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
        ->and($error->message)->toBe('Server overloaded');
});

test('streaming captures usage from response completed', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDone('Hello'),
                $this->responseCompleted(42, 10, cachedTokens: 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(37)
        ->and($streamEnd->usage->completionTokens)->toBe(10)
        ->and($streamEnd->usage->cacheReadInputTokens)->toBe(5);
});

test('streaming finish reason maps correctly', function (string $status, string $type, $expected): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDone('Hello'),
                $this->responseCompleted(10, 5, output: [
                    ['type' => $type, 'status' => $status, 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]],
                ]),
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

test('streaming captures cache write tokens from response completed', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDone('Hello'),
                $this->responseCompleted(8817, 120, cachedTokens: 0, cacheWriteTokens: 8814),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->cacheWriteInputTokens)->toBe(8814)
        ->and($streamEnd->usage->cacheReadInputTokens)->toBe(0)
        ->and($streamEnd->usage->promptTokens)->toBe(3)
        ->and($streamEnd->usage->completionTokens)->toBe(120);
});
