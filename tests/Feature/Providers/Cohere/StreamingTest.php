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
use Crustum\Ai\Streaming\Event\ToolResult as ToolResultEvent;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.cohere', [
        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
    ]);
});

test('streaming emits text events', function (): void {
    aiHttpFake(['*' => $this->fakeStreamResponse($this->streamTextEvents('Hello', ' world'))]);

    $events = $this->collectStreamEvents();

    expect($events[0])->toBeInstanceOf(StreamStart::class)
        ->and($events[1])->toBeInstanceOf(TextStart::class)
        ->and($events[2])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and($events[3])->toBeInstanceOf(TextDelta::class)->delta->toBe(' world')
        ->and($events[4])->toBeInstanceOf(TextEnd::class)
        ->and($events[5])->toBeInstanceOf(StreamEnd::class)
        ->and($events)->toHaveCount(6);
});

test('streaming captures usage and finish reason', function (): void {
    aiHttpFake(['*' => $this->fakeStreamResponse($this->streamTextEvents('Hi'))]);

    $streamEnd = collect($this->collectStreamEvents())->last();

    expect($streamEnd)->toBeInstanceOf(StreamEnd::class)
        ->and($streamEnd->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnd->usage->inputTokens)->toBe(20)
        ->and($streamEnd->usage->outputTokens)->toBe(10);
});

test('streaming accumulates tool call arguments and runs the tool', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeStreamResponse($this->streamToolCallEvents('FixedNumberGenerator', 'get_number_ejj5xe67w3e1', ['{"', 'city', '":', ' "', 'Paris', '"}'])),
            $this->fakeStreamResponse($this->streamTextEvents('The number is 72019')),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

    $toolCalls = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent));
    $toolResults = array_values(array_filter($events, fn($e): bool => $e instanceof ToolResultEvent));
    $streamEnds = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd));

    expect($toolCalls)->toHaveCount(1)
        ->and($toolCalls[0]->toolCall->id)->toBe('get_number_ejj5xe67w3e1')
        ->and($toolCalls[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolCalls[0]->toolCall->arguments)->toBe(['city' => 'Paris'])
        ->and($toolResults)->toHaveCount(1)
        ->and($streamEnds)->toHaveCount(1)
        ->and($streamEnds[0]->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnds[0]->usage->inputTokens)->toBe(30)
        ->and($streamEnds[0]->usage->outputTokens)->toBe(15);

    $followUp = json_decode((string)aiHttpRecorded()[1][0]->body(), true);

    expect(collect($followUp['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'tool')->first()['tool_call_id'])->toBe('get_number_ejj5xe67w3e1');
});

test('streaming ignores tool plan deltas', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeStreamResponse($this->streamToolCallEvents()),
            $this->fakeStreamResponse($this->streamTextEvents('Done')),
        ]),
    ]);

    $deltas = collect($this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent()))
        ->filter(fn($e): bool => $e instanceof TextDelta)
        ->map(fn(TextDelta $e): string => $e->delta)
        ->toList();

    expect($deltas)->toBe(['Done']);
});

test('streaming emits reasoning events before the text', function (): void {
    aiHttpFake(['*' => $this->fakeStreamResponse([
        ['type' => 'message-start', 'id' => 'msg_1', 'delta' => ['message' => ['role' => 'assistant']]],
        ['type' => 'content-start', 'index' => 0, 'delta' => ['message' => ['content' => ['type' => 'thinking', 'thinking' => '']]]],
        ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => 'Let me ']]]],
        ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => 'think...']]]],
        ['type' => 'content-end', 'index' => 0],
        ['type' => 'content-start', 'index' => 1, 'delta' => ['message' => ['content' => ['type' => 'text', 'text' => '']]]],
        ['type' => 'content-delta', 'index' => 1, 'delta' => ['message' => ['content' => ['text' => 'Hello']]]],
        ['type' => 'content-end', 'index' => 1],
        ['type' => 'message-end', 'delta' => ['finish_reason' => 'COMPLETE', 'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 5]]]],
    ])]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello');
});

test('streaming exposes the combined reasoning on the response', function (): void {
    aiHttpFake(['*' => $this->fakeStreamResponse([
        ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => 'Let me ']]]],
        ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => 'think...']]]],
        ['type' => 'content-delta', 'index' => 1, 'delta' => ['message' => ['content' => ['text' => 'Hello']]]],
        ['type' => 'message-end', 'delta' => ['finish_reason' => 'COMPLETE', 'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 5]]]],
    ])]);

    $stream = (new AssistantAgent())->stream('Hello', provider: 'cohere');

    iterator_to_array($stream);

    expect($stream->reasoning)->toBe('Let me think...')
        ->and($stream->text)->toBe('Hello');
});

test('streaming error event stops stream', function (): void {
    aiHttpFake(['*' => $this->fakeStreamResponse([
        ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => 'Hel']]]],
        ['type' => 'message-end', 'delta' => ['finish_reason' => 'TIMEOUT', 'error' => 'Generation timed out']],
    ])]);

    $error = null;

    try {
        $this->collectStreamEvents();
    } catch (StreamErrorException $streamErrorException) {
        $error = $streamErrorException->error;
    }

    expect($error)->toBeInstanceOf(Error::class)
        ->and($error->type)->toBe('timeout')
        ->and($error->message)->toBe('Generation timed out');
});
