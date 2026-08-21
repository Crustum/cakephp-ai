<?php
declare(strict_types=1);

use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Streaming\Event\Citation as CitationEvent;
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
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;

    test('streaming emits text events', function (): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => ' world']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10),
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

    test('streaming starts a new text part after each text block', function (): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'First']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search']),
                    $this->contentBlockStop(1),
                    $this->contentBlockStart(2, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(2, ['type' => 'text_delta', 'text' => 'Second']),
                    $this->contentBlockStop(2),
                    $this->messageDelta('end_turn', 10),
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
            'api.anthropic.com/*' => aiHttpSequence([
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->messageStart(),
                        $this->contentBlockStart(0, ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => '']),
                        $this->contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{}']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('tool_use', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                aiHttpResponse([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

        $toolCallEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent));

        expect($toolCallEvents)->not->toBeEmpty()
            ->and($toolCallEvents[0]->toolCall)->name->toBe('FixedNumberGenerator')->id->toBe('toolu_1');
    });

    test('streaming handles thinking blocks', function (): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'thinking', 'thinking' => '']),
                    $this->contentBlockDelta(0, ['type' => 'thinking_delta', 'thinking' => 'Let me think...']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Answer']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 15),
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
    })->skip('Unsupported on Cake 4');

    test('streaming handles server tool use', function (): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Result']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $providerEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ProviderToolEvent));

        expect($providerEvents)->toHaveCount(2)
            ->and($providerEvents[0])->status->toBe('started')->itemId->toBe('srvtoolu_1')
            ->and($providerEvents[1]->status)->toBe('completed');
    });

    test('streaming handles provider tool results', function (): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'search_results' => []]),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Found it']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $providerEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ProviderToolEvent));

        expect($providerEvents)->not->toBeEmpty()
            ->and($providerEvents[0])->status->toBe('result_received')->type->toBe('web_search_tool_result');
    });

    test('streaming pause_turn triggers follow-up stream with assistant replayed', function (): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpSequence([
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->messageStart(),
                        $this->contentBlockStart(0, ['type' => 'server_tool_use', 'id' => 'srvtoolu_pause', 'name' => 'web_search']),
                        $this->contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{"query":"crustum ai"}']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('pause_turn', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                aiHttpResponse(
                    body: $this->ssePayload([
                        $this->messageStart(),
                        $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                        $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Resumed']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('end_turn', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        $recorded = aiHttpRecorded();
        expect($recorded)->toHaveCount(2);

        $followUpBody = json_decode($recorded[1][0]->body(), false, 512, JSON_THROW_ON_ERROR);
        $lastMessage = end($followUpBody->messages);

        expect($lastMessage->role)->toBe('assistant')
            ->and($lastMessage->content[0]->type)->toBe('server_tool_use')
            ->and($lastMessage->content[0]->input)->toBeInstanceOf(stdClass::class)
            ->and($lastMessage->content[0]->input->query)->toBe('crustum ai');

        $textDeltas = array_values(array_filter($events, fn($e): bool => $e instanceof TextDelta));
        expect($textDeltas)->not->toBeEmpty()
            ->and($textDeltas[0]->delta)->toBe('Resumed');
    });

    test('streaming error event stops stream', function (): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Server overloaded']],
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

        expect($error)->toBeInstanceOf(Error::class)->type->toBe('overloaded_error')->message->toBe('Server overloaded');
    });

    test('streaming captures input tokens from message start', function (): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    [
                        'type' => 'message_start',
                        'message' => [
                            'id' => 'msg_1',
                            'model' => 'claude-sonnet-4-6',
                            'role' => 'assistant',
                            'content' => [],
                            'usage' => [
                                'input_tokens' => 42,
                                'output_tokens' => 0,
                                'cache_creation_input_tokens' => 100,
                                'cache_read_input_tokens' => 50,
                            ],
                        ],
                    ],
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

        expect($streamEnd->usage)
            ->promptTokens->toBe(42)
            ->completionTokens->toBe(10)
            ->cacheWriteInputTokens->toBe(100)
            ->cacheReadInputTokens->toBe(50);
    });

    test('streaming finish reason maps correctly', function (string $apiReason, $expected): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockStop(0),
                    $this->messageDelta($apiReason, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

        expect($streamEnd->reason)->toBe($expected->value);
    })->with([
        'end_turn maps to Stop' => ['end_turn', FinishReason::Stop],
        'stop_sequence maps to Stop' => ['stop_sequence', FinishReason::Stop],
        'max_tokens maps to Length' => ['max_tokens', FinishReason::Length],
        'model_context_window_exceeded maps to Length' => ['model_context_window_exceeded', FinishReason::Length],
        'refusal maps to ContentFilter' => ['refusal', FinishReason::ContentFilter],
        'tool_use without tool blocks normalizes to Stop (StreamEnd still emitted)' => ['tool_use', FinishReason::Stop],
    ]);


test('streaming tool loop emits a single stream end with accumulated usage', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            aiHttpResponse(
                body: $this->ssePayload([
                    [
                        'type' => 'message_start',
                        'message' => [
                            'id' => 'msg_1',
                            'model' => 'claude-sonnet-4-6',
                            'role' => 'assistant',
                            'content' => [],
                            'usage' => [
                                'input_tokens' => 10,
                                'output_tokens' => 0,
                                'cache_creation_input_tokens' => 3,
                                'cache_read_input_tokens' => 2,
                            ],
                        ],
                    ],
                    $this->contentBlockStart(0, ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => '']),
                    $this->contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{}']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('tool_use', 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            aiHttpResponse(
                body: $this->ssePayload([
                    [
                        'type' => 'message_start',
                        'message' => [
                            'id' => 'msg_2',
                            'model' => 'claude-sonnet-4-6',
                            'role' => 'assistant',
                            'content' => [],
                            'usage' => [
                                'input_tokens' => 20,
                                'output_tokens' => 0,
                                'cache_creation_input_tokens' => 7,
                                'cache_read_input_tokens' => 8,
                            ],
                        ],
                    ],
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'The number is 72019']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10),
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
        ->completionTokens->toBe(15)
        ->cacheWriteInputTokens->toBe(10)
        ->cacheReadInputTokens->toBe(10);
})->skip('Unsupported on Cake 4');
    test('streaming emits a citation for a fetched url', function (): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, [
                        'type' => 'web_fetch_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => [
                            'type' => 'web_fetch_result',
                            'url' => 'https://example.com/article',
                            'content' => ['type' => 'document', 'title' => 'Article Title'],
                        ],
                    ]),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'The article argues X.']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $citations = array_values(array_filter($events, fn($e): bool => $e instanceof CitationEvent));

        expect($citations)->toHaveCount(1)
            ->and($citations[0]->citation)->url->toBe('https://example.com/article')->title->toBe('Article Title');
    })->skip('Unsupported on Cake 4');;

    test('streaming skips citations for a failed fetch', function (): void {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpResponse(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, [
                        'type' => 'web_fetch_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => ['type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible'],
                    ]),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $citations = array_filter($this->collectStreamEvents(), fn($e): bool => $e instanceof CitationEvent);

        expect($citations)->toBeEmpty();
    });

