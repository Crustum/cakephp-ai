<?php
declare(strict_types=1);

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

    test('streaming emits text events', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Hello']]),
                    $this->geminiChunk([['text' => ' world']]),
                    $this->geminiChunkWithUsage([['text' => '']], 10, 5),
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
            ->and($events[count($events) - 2])->toBeInstanceOf(TextEnd::class)
            ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);
    });

    test('streaming uses sse endpoint with alt parameter', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->geminiChunkWithUsage([['text' => 'Hello']], 10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $this->collectStreamEvents();

        aiAssertHttpSent(fn($request): bool => str_contains((string)$request->url(), 'streamGenerateContent?alt=sse'));
    });

    test('streaming handles tool calls', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([[
                            'functionCall' => [
                                'id' => 'call_1',
                                'name' => 'FixedNumberGenerator',
                                'args' => (object)[],
                            ],
                        ]], 10, 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([['text' => 'The number is 72019']], 20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

        $toolCallEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent));

        expect($toolCallEvents)->not->toBeEmpty()
            ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator');
    });

    test('streaming tool loop emits a single stream end with accumulated usage', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([[
                            'functionCall' => [
                                'id' => 'call_1',
                                'name' => 'FixedNumberGenerator',
                                'args' => (object)[],
                            ],
                        ]], 10, 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([['text' => 'The number is 72019']], 20, 10),
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
            ->and($streamEnds[0]->usage)
            ->promptTokens->toBe(30)
            ->completionTokens->toBe(15);
    });

    test('streaming thinking parts are excluded from tool call continuation', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->geminiChunk([
                            ['text' => 'thinking...', 'thought' => true],
                            ['functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object)[]]],
                        ]),
                        $this->geminiChunkWithUsage([], 10, 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([['text' => 'Done']], 20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

        $recorded = aiHttpRecorded();
        expect($recorded)->toHaveCount(2);

        $followUpContents = $recorded[1][0]->data()['contents'];

        foreach ($followUpContents as $content) {
            if ($content['role'] === 'model') {
                foreach ($content['parts'] as $part) {
                    expect($part['thought'] ?? false)->toBeFalse('Streaming: thinking parts should be excluded from tool call continuation');
                }
            }
        }
    });

    test('streaming preserves the thought signature across the tool call continuation', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->geminiChunk([['text' => 'thinking...', 'thought' => true]]),
                        $this->geminiChunkWithUsage([[
                            'functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object)[]],
                            'thoughtSignature' => 'sig_stream_555',
                        ]], 10, 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([['text' => 'The number is 72019']], 20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

        $followUpContents = aiHttpRecorded()[1][0]->data()['contents'];

        $signature = null;

        foreach ($followUpContents as $content) {
            if ($content['role'] === 'model') {
                foreach ($content['parts'] as $part) {
                    if (isset($part['functionCall'])) {
                        $signature = $part['thoughtSignature'] ?? null;
                    }
                }
            }
        }

        expect($signature)->toBe('sig_stream_555');
    });

    test('streaming handles thinking parts', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Let me think...', 'thought' => true]]),
                    $this->geminiChunk([['text' => 'Answer']]),
                    $this->geminiChunkWithUsage([], 10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        expect($events)->toContainStreamEventTypes([
            ReasoningStart::class,
            ReasoningDelta::class,
            ReasoningEnd::class,
        ]);

        $reasoningDelta = array_values(array_filter($events, fn($e): bool => $e instanceof ReasoningDelta))[0];
        expect($reasoningDelta->delta)->toBe('Let me think...');
    });

    test('streaming error event stops stream', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    ['error' => ['code' => 'overloaded', 'message' => 'Server overloaded']],
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

        expect($error)->toBeInstanceOf(Error::class)->type->toBe('overloaded')->message->toBe('Server overloaded');
    });

    test('streaming captures usage from final chunk', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Hello']]),
                    $this->geminiChunkWithUsage([['text' => '']], 42, 10, cachedTokens: 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

        expect($streamEnd->usage)
            ->promptTokens->toBe(37)
            ->completionTokens->toBe(10)
            ->cacheReadInputTokens->toBe(5);
    });

    test('streaming finish reason maps correctly', function (string $geminiReason, $expected): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Hello']]),
                    $this->geminiChunkWithUsage([['text' => '']], 10, 5, finishReason: $geminiReason),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

        expect($streamEnd->reason)->toBe($expected->value);
    })->with([
        'STOP maps to Stop' => ['STOP', FinishReason::Stop],
        'MAX_TOKENS maps to Length' => ['MAX_TOKENS', FinishReason::Length],
        'SAFETY maps to ContentFilter' => ['SAFETY', FinishReason::ContentFilter],
        'MALFORMED_FUNCTION_CALL maps to ContentFilter' => ['MALFORMED_FUNCTION_CALL', FinishReason::ContentFilter],
        'RECITATION maps to ContentFilter' => ['RECITATION', FinishReason::ContentFilter],
    ]);
