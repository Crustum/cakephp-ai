<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Streaming\Event\ToolResult as ToolResultEvent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.mistral', [

        ...(array)Configure::read('Ai.providers.mistral'),
        'key' => 'test-key',
    ]);
});

test('streaming emits text events', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
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

test('streaming flattens block content deltas', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello']]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => [['type' => 'reference', 'reference_ids' => ['search_documents']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    expect($events[0])->toBeInstanceOf(StreamStart::class)
        ->and($events[1])->toBeInstanceOf(TextStart::class)
        ->and($events[2])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and($events[3])->toBeInstanceOf(TextEnd::class)
        ->and($events[4])->toBeInstanceOf(StreamEnd::class);
});

test('streaming handles tool calls', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse(
                body: $this->ssePayload([
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            aiHttpResponse(
                body: $this->ssePayload([
                    ['id' => 'chatcmpl-456', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-456', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

    $toolCallEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent));
    $toolResultEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolResultEvent));
    $streamEndEvents = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd));

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolResultEvents)->not->toBeEmpty()
        ->and($streamEndEvents)->toHaveCount(1)
        ->and($streamEndEvents[0]->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEndEvents[0]->usage->promptTokens)->toBe(30)
        ->and($streamEndEvents[0]->usage->completionTokens)->toBe(15);
});

test('streaming captures usage', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(10)
        ->and($streamEnd->usage->completionTokens)->toBe(5);
});

test('streaming error event stops stream', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['error' => ['code' => 'server_error', 'message' => 'Internal server error']],
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

test('streaming finish reason maps correctly', function (string $apiReason, $expected): void {
    aiHttpFake([
        '*' => aiHttpResponse(
            body: $this->ssePayload([
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $apiReason]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe($expected->value);
})->with([
    'stop maps to Stop' => ['stop', FinishReason::Stop],
    'tool_calls maps to ToolCalls' => ['tool_calls', FinishReason::ToolCalls],
    'length maps to Length' => ['length', FinishReason::Length],
    'content_filter maps to ContentFilter' => ['content_filter', FinishReason::ContentFilter],
    'unknown maps to Unknown' => ['unknown_reason', FinishReason::Unknown],
]);
