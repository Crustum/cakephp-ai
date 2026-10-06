<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Streaming\Event\Citation as CitationEvent;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Streaming\Event\ToolResult as ToolResultEvent;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

test('streaming emits text events', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse($this->ssePayload([
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]],
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $types = array_map(fn($e): string => $e::class, $events);

    expect($types)->toContain(StreamStart::class)
        ->toContain(TextStart::class)
        ->toContain(TextDelta::class)
        ->toContain(TextEnd::class)
        ->toContain(StreamEnd::class);

    $textDeltas = array_values(array_filter($events, fn($e): bool => $e instanceof TextDelta));
    expect($textDeltas[0]->delta)->toBe('Hello')
        ->and($textDeltas[1]->delta)->toBe(' world');
});

test('streaming reports cached tokens within the input token count', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse($this->ssePayload([
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 5, 'prompt_tokens_details' => ['cached_tokens' => 20, 'cache_write_tokens' => 30]]],
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd));
    expect($streamEnd)->toHaveCount(1)
        ->and($streamEnd[0]->usage->inputTokens)->toBe(100)
        ->and($streamEnd[0]->usage->outputTokens)->toBe(5)
        ->and($streamEnd[0]->usage->cacheReadInputTokens)->toBe(20)
        ->and($streamEnd[0]->usage->cacheWriteInputTokens)->toBe(30);
});

test('streaming handles tool calls', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_123', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 10]],
            ])),
            aiHttpResponse($this->ssePayload([
                ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5]],
            ])),
        ]),
    ]);

    $events = [];
    foreach (agent(tools: [new FixedNumberGenerator()])->stream('Give me a number', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $toolCallEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent));
    $toolResultEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolResultEvent));
    $streamEndEvents = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd));

    expect($toolCallEvents)->toHaveCount(1)
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolResultEvents)->toHaveCount(1)
        ->and($streamEndEvents)->toHaveCount(1)
        ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class)
        ->and($streamEndEvents[0]->reason)->toBe(FinishReason::Stop->value);
});

test('streaming tool loop emits a single stream end with accumulated usage', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_123', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            ])),
            aiHttpResponse($this->ssePayload([
                ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]],
            ])),
        ]),
    ]);

    $events = [];

    foreach (agent(tools: [new FixedNumberGenerator()])->stream('Give me a number', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $streamEndEvents = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd));

    expect($streamEndEvents)->toHaveCount(1)
        ->and($streamEndEvents[0]->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEndEvents[0]->usage->inputTokens)->toBe(30)
        ->and($streamEndEvents[0]->usage->outputTokens)->toBe(15);
});

test('streaming error event stops stream', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse($this->ssePayload([
            ['error' => ['code' => 'server_error', 'message' => 'Internal error']],
        ])),
    ]);

    $events = [];
    $error = null;

    try {
        foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
            $events[] = $event;
        }
    } catch (StreamErrorException $streamErrorException) {
        $error = $streamErrorException->error;
    }

    $errorEvents = array_values(array_filter($events, fn($e): bool => $e instanceof Error));

    expect($errorEvents)->toHaveCount(1)
        ->and($errorEvents[0]->type)->toBe('server_error')
        ->and($error)->toBe($errorEvents[0]);
});

test('streaming error finish reason emits error event', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse($this->ssePayload([
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Partial'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'error', 'error' => ['code' => 502, 'message' => 'Provider returned error']]]],
        ])),
    ]);

    $events = [];
    $error = null;

    try {
        foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
            $events[] = $event;
        }
    } catch (StreamErrorException $streamErrorException) {
        $error = $streamErrorException->error;
    }

    $errorEvents = array_values(array_filter($events, fn($e): bool => $e instanceof Error));

    expect($errorEvents)->toHaveCount(1)
        ->and($errorEvents[0]->type)->toBe('502')
        ->and($error)->toBe($errorEvents[0]);
});

test('streaming captures usage from final chunk', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse($this->ssePayload([
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [], 'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 3]],
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd));
    expect($streamEnd)->toHaveCount(1)
        ->and($streamEnd[0]->usage->inputTokens)->toBe(15)
        ->and($streamEnd[0]->usage->outputTokens)->toBe(3);
});

test('streaming finish reason maps correctly', function (string $apiReason, $expected): void {
    aiHttpFake([
        '*' => aiHttpResponse($this->ssePayload([
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $apiReason]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe($expected->value);
})->with([
    'stop maps to Stop' => ['stop', FinishReason::Stop],
    'tool_calls maps to ToolCalls' => ['tool_calls', FinishReason::ToolCalls],
    'length maps to Length' => ['length', FinishReason::Length],
    'content_filter maps to ContentFilter' => ['content_filter', FinishReason::ContentFilter],
    'unknown maps to Unknown' => ['unknown_reason', FinishReason::Unknown],
]);

test('streaming emits citation events for web search annotations', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'content' => 'Paris is the capital']),
            $this->chatChunk(['content' => ' of France.', 'annotations' => [
                [
                    'type' => 'url_citation',
                    'url_citation' => [
                        'url' => 'https://example.com/paris',
                        'title' => 'Paris - Wikipedia',
                        'start_index' => 0,
                        'end_index' => 30,
                    ],
                ],
            ]]),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('What is the capital of France?', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $citations = array_values(array_filter($events, fn($e): bool => $e instanceof CitationEvent));

    expect($citations)->toHaveCount(1)
        ->and($citations[0]->citation->url)->toBe('https://example.com/paris')
        ->and($citations[0]->citation->title)->toBe('Paris - Wikipedia')
        ->and($citations[0]->citation->startIndex)->toBe(0)
        ->and($citations[0]->citation->endIndex)->toBe(30);
});

test('streaming emits multiple citation events across chunks', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'content' => 'Answer', 'annotations' => [
                [
                    'type' => 'url_citation',
                    'url_citation' => ['url' => 'https://example.com/one', 'title' => 'One'],
                ],
            ]]),
            $this->chatChunk(['content' => ' more', 'annotations' => [
                [
                    'type' => 'url_citation',
                    'url_citation' => ['url' => 'https://example.com/two', 'title' => 'Two'],
                ],
            ]]),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 5, 'completion_tokens' => 2]),
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Question', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $citations = array_values(array_filter($events, fn($e): bool => $e instanceof CitationEvent));

    expect($citations)->toHaveCount(2)
        ->and($citations[0]->citation->url)->toBe('https://example.com/one')
        ->and($citations[1]->citation->url)->toBe('https://example.com/two');
});

test('streaming ignores non-url-citation annotation types', function (): void {
    aiHttpFake([
        '*' => aiHttpResponse($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'content' => 'Answer', 'annotations' => [
                ['type' => 'other_type', 'data' => 'something'],
            ]]),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 5, 'completion_tokens' => 2]),
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Question', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $citations = array_values(array_filter($events, fn($e): bool => $e instanceof CitationEvent));

    expect($citations)->toHaveCount(0);
});
