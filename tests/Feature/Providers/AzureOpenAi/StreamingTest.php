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
    Configure::write('Ai.providers.azure', [

        ...(array)Configure::read('Ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]);
});

test('streaming emits text events', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpResponse(
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

test('streaming handles reasoning text events', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                ['type' => 'response.reasoning_text.delta', 'delta' => 'Let me think...', 'item_id' => 'rs_1'],
                [
                    'type' => 'response.output_item.done',
                    'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
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

    $types = array_map(fn($event) => $event::class, $events);

    expect($types)->toContain(ReasoningStart::class)
        ->toContain(ReasoningDelta::class)
        ->toContain(ReasoningEnd::class);

    $reasoningDelta = array_values(array_filter($events, fn($event): bool => $event instanceof ReasoningDelta))[0];

    expect($reasoningDelta->delta)->toBe('Let me think...');
});

test('streaming handles tool calls', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpSequence([
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
                    $this->responseCreated(),
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
        ->and($streamEnd->usage->inputTokens)->toBe(30)
        ->and($streamEnd->usage->outputTokens)->toBe(15);
});

test('streaming error event stops stream', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                ['type' => 'error', 'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit exceeded']],
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
        ->and($error->type)->toBe('rate_limit_exceeded')
        ->and($error->message)->toBe('Rate limit exceeded');
});

test('streaming captures usage from completed event', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDone('Hello'),
                $this->responseCompleted(42, 10),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->inputTokens)->toBe(42)
        ->and($streamEnd->usage->outputTokens)->toBe(10);
});

test('streaming finish reason maps correctly', function (array $output, $expected): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->responseCompleted(10, 5, output: $output),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe($expected->value);
})->with([
    'completed message maps to Stop' => [
        [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]],
        FinishReason::Stop,
    ],
    'incomplete maps to Length' => [
        [['type' => 'message', 'status' => 'incomplete', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]],
        FinishReason::Length,
    ],
    'completed function_call maps to ToolCalls' => [
        [['type' => 'function_call', 'status' => 'completed', 'name' => 'FixedNumberGenerator', 'arguments' => '{}']],
        FinishReason::ToolCalls,
    ],
    'failed maps to Error' => [
        [['type' => 'message', 'status' => 'failed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]],
        FinishReason::Error,
    ],
    'unknown status maps to Unknown' => [
        [['type' => 'message', 'status' => 'mystery_status', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]],
        FinishReason::Unknown,
    ],
    'completed unknown type maps to Unknown' => [
        [['type' => 'mystery_output', 'status' => 'completed']],
        FinishReason::Unknown,
    ],
]);
