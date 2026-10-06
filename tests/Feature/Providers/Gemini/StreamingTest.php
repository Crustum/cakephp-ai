<?php
declare(strict_types=1);

use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Streaming\Event\Citation;
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
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

function geminiStreamResponse(array $events): AiHttpResponseDefinition
{
    return aiHttpResponse(
        body: test()->ssePayload($events),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    );
}

describe('text streaming', function (): void {
    test('streaming emits provider tool events for code execution steps', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepStart(0, ['type' => 'code_execution_call', 'id' => 'ce_1']),
                $this->stepDelta(0, 'text', 'print(1)'),
                $this->stepStop(0),
                $this->stepStart(1, ['type' => 'code_execution_result', 'id' => 'ce_1']),
                $this->stepDelta(1, 'text', '1'),
                $this->stepStop(1),
                $this->stepStart(2, ['type' => 'model_output']),
                $this->stepDelta(2, 'text', 'The answer is 1.'),
                $this->stepStop(2),
                $this->interactionCompleted(),
            ]),
        ]);

        $providerEvents = array_values(array_filter($this->collectStreamEvents(), fn($e): bool => $e instanceof ProviderToolEvent));

        expect(array_map(fn(ProviderToolEvent $e): string => $e->status, $providerEvents))->toBe(['completed', 'result_received'])
            ->and($providerEvents[0]->type)->toBe('code_execution')
            ->and($providerEvents[0]->provider)->toBe('gemini')
            ->and($providerEvents[1]->type)->toBe('code_execution');
    });

    test('streaming keeps the step payload gemini only sends as deltas', function (): void {
        $rawDelta = fn(int $index, array $delta): array => ['event_type' => 'step.delta', 'index' => $index, 'delta' => $delta];

        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                geminiStreamResponse([
                    $this->stepStart(0, ['type' => 'thought']),
                    $rawDelta(0, ['type' => 'thought_signature', 'signature' => 'sig_abc']),
                    $this->stepStop(0),
                    $this->stepStart(1, ['type' => 'code_execution_call', 'id' => 'ce_1']),
                    $rawDelta(1, ['type' => 'code_execution_call', 'arguments' => ['language' => 'python', 'code' => 'print(1)']]),
                    $this->stepStop(1),
                    $this->stepStart(2, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => []]),
                    $this->stepStop(2),
                    $this->interactionCompleted(),
                ]),
                geminiStreamResponse([
                    $this->stepStart(0, ['type' => 'model_output']),
                    $this->stepDelta(0, 'text', 'Done.'),
                    $this->interactionCompleted(),
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent(tools: [new FixedNumberGenerator()]));

        $providerEvent = array_values(array_filter($events, fn($e): bool => $e instanceof ProviderToolEvent))[0];

        [$request] = aiHttpRecorded()[1];

        expect($providerEvent->data['arguments'])->toBe(['language' => 'python', 'code' => 'print(1)'])
            ->and($request->data()['input'][1])->toBe(['type' => 'thought', 'signature' => 'sig_abc'])
            ->and($request->body())->toContain('"name":"FixedNumberGenerator","arguments":{}');
    });

    test('streaming replays provider tool steps when continuing after a function call', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                geminiStreamResponse([
                    $this->stepStart(0, ['type' => 'code_execution_call', 'id' => 'ce_1', 'content' => [['type' => 'text', 'text' => 'print(1)']]]),
                    $this->stepStop(0),
                    $this->stepStart(1, ['type' => 'code_execution_result', 'id' => 'ce_1', 'content' => [['type' => 'text', 'text' => '1']]]),
                    $this->stepStop(1),
                    $this->stepStart(2, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => []]),
                    $this->stepStop(2),
                    $this->interactionCompleted(),
                ]),
                geminiStreamResponse([
                    $this->stepStart(0, ['type' => 'model_output']),
                    $this->stepDelta(0, 'text', 'Done.'),
                    $this->interactionCompleted(),
                ]),
            ]),
        ]);

        $this->collectStreamEvents(agent(tools: [new FixedNumberGenerator()]));

        aiAssertHttpSentCount(2);

        $input = aiHttpRecorded()[1][0]->data()['input'];

        expect(array_column($input, 'type'))->toBe([
            'user_input', 'code_execution_call', 'code_execution_result', 'function_call', 'function_result',
        ]);
    });

    test('streaming emits citation events for annotations on the completed interaction', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepStart(0, ['type' => 'model_output']),
                $this->stepDelta(0, 'text', 'Spain won Euro 2024.'),
                ['event_type' => 'step.stop', 'index' => 0, 'step' => ['annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://example.com/euro', 'title' => 'Euro 2024'],
                    ['type' => 'url_citation', 'url' => 'https://example.com/spain', 'title' => 'Spain Wins'],
                ]]],
                $this->interactionCompleted(),
            ]),
        ]);

        $citations = array_values(array_filter($this->collectStreamEvents(), fn($e): bool => $e instanceof Citation));

        expect($citations)->toHaveCount(2)
            ->and($citations[0]->citation->url)->toBe('https://example.com/euro')
            ->and($citations[1]->citation->url)->toBe('https://example.com/spain');
    });

    test('streaming emits text events', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepStart(0, ['type' => 'model_output']),
                $this->stepDelta(0, 'text', 'Hello'),
                $this->stepDelta(0, 'text', ' world'),
                $this->stepStop(0),
                $this->interactionCompleted(),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        expect($events[0])->toBeInstanceOf(StreamStart::class)
            ->and($events[1])->toBeInstanceOf(TextStart::class)
            ->and($events[2])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
            ->and($events[3])->toBeInstanceOf(TextDelta::class)->delta->toBe(' world')
            ->and($events[count($events) - 2])->toBeInstanceOf(TextEnd::class)
            ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);
    });

    test('streaming posts to the interactions endpoint with sse and the stream flag', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepDelta(0, 'text', 'Hello'),
                $this->interactionCompleted(),
            ]),
        ]);

        $this->collectStreamEvents();

        expect(sentRequest()->url())->toContain('interactions?alt=sse')
            ->and(sentRequest()->data())->toMatchArray(['stream' => true]);
    });
});

describe('tool calls', function (): void {
    test('streaming handles tool calls', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                geminiStreamResponse([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(0),
                    $this->interactionCompleted(),
                ]),
                geminiStreamResponse([
                    $this->stepDelta(0, 'text', 'The number is 72019'),
                    $this->interactionCompleted(),
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

        $toolCallEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent));

        expect($toolCallEvents)->not->toBeEmpty()
            ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
            ->and($toolCallEvents[0]->toolCall->id)->toBe('call_1');
    });

    test('streaming accumulates function call arguments from argument deltas', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                geminiStreamResponse([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->argumentsDelta(0, '{"see'),
                    $this->argumentsDelta(0, 'd": 7}'),
                    $this->stepStop(0),
                    ['event_type' => 'interaction.completed', 'status' => 'completed'],
                ]),
                geminiStreamResponse([
                    $this->stepDelta(0, 'text', 'Done'),
                    $this->interactionCompleted(),
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

        $toolCall = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent))[0];

        expect($toolCall->toolCall->arguments)->toBe(['seed' => 7]);
    });

    test('streamed argument deltas are replayed to gemini as an object', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                geminiStreamResponse([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->argumentsDelta(0, '{"see'),
                    $this->argumentsDelta(0, 'd": 7}'),
                    $this->stepStop(0),
                    $this->interactionCompleted([], 'requires_action'),
                ]),
                geminiStreamResponse([
                    $this->stepDelta(0, 'text', 'Done'),
                    $this->interactionCompleted(),
                ]),
            ]),
        ]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

        expect(aiHttpRecorded()[1][0]->body())
            ->toContain('"name":"FixedNumberGenerator","arguments":{"seed":7}');
    });

    test('streaming tool loop emits a single stream end with accumulated usage', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                geminiStreamResponse([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(0),
                    $this->interactionCompleted(['total_input_tokens' => 10, 'total_output_tokens' => 5]),
                ]),
                geminiStreamResponse([
                    $this->stepDelta(0, 'text', 'The number is 72019'),
                    $this->interactionCompleted(['total_input_tokens' => 20, 'total_output_tokens' => 10]),
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

        $streamEnds = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd));

        expect($streamEnds)->toHaveCount(1)
            ->and($streamEnds[0]->reason)->toBe(FinishReason::Stop->value)
            ->and($streamEnds[0]->usage)
            ->inputTokens->toBe(30)
            ->outputTokens->toBe(15);
    });

    test('streaming replays thought steps verbatim into the tool call continuation', function (): void {
        $thought = [
            'type' => 'thought',
            'summary' => [['type' => 'text', 'text' => 'thinking...']],
            'signature' => 'sig_stream_555',
        ];

        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                geminiStreamResponse([
                    $this->stepStart(0, ['type' => 'thought']),
                    $this->stepDelta(0, 'thought_summary', 'thinking...'),
                    ['event_type' => 'step.delta', 'index' => 0, 'delta' => ['type' => 'thought_signature', 'signature' => 'sig_stream_555']],
                    $this->stepStop(0),
                    $this->stepStart(1, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(1),
                    $this->interactionCompleted(),
                ]),
                geminiStreamResponse([
                    $this->stepDelta(0, 'text', 'Done'),
                    $this->interactionCompleted(),
                ]),
            ]),
        ]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

        $replayed = array_values(array_filter(
            aiHttpRecorded()[1][0]->data()['input'],
            fn(array $step): bool => $step['type'] === 'thought',
        ));

        expect($replayed)->toBe([$thought]);
    });

    test('a completed event sent without an interaction wrapper still closes the stream', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => aiHttpSequence([
                geminiStreamResponse([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(0),
                    ['event_type' => 'interaction.completed', 'status' => 'completed'],
                ]),
                geminiStreamResponse([
                    $this->stepDelta(0, 'text', 'Done'),
                    $this->interactionCompleted(),
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

        $toolCalls = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent));

        expect($toolCalls)->toHaveCount(1)
            ->and($toolCalls[0]->toolCall->id)->toBe('call_1');
    });
});

describe('thinking blocks', function (): void {
    test('streaming handles thought summary deltas', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepStart(0, ['type' => 'thought']),
                $this->stepDelta(0, 'thought_summary', 'Let me think...'),
                $this->stepStop(0),
                $this->stepStart(1, ['type' => 'model_output']),
                $this->stepDelta(1, 'text', 'Answer'),
                $this->stepStop(1),
                $this->interactionCompleted(),
            ]),
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
});

describe('error handling', function (): void {
    test('streaming error event stops stream', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                ['event_type' => 'error', 'error' => ['code' => 'overloaded', 'message' => 'Server overloaded']],
            ]),
        ]);

        $error = null;

        try {
            $this->collectStreamEvents();
        } catch (StreamErrorException $streamErrorException) {
            $error = $streamErrorException->error;
        }

        expect($error)->toBeInstanceOf(Error::class)->type->toBe('overloaded')->message->toBe('Server overloaded');
    });
});

describe('usage tracking', function (): void {
    test('streaming captures usage from the completed event', function (): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepDelta(0, 'text', 'Hello'),
                $this->interactionCompleted([
                    'total_input_tokens' => 42,
                    'total_output_tokens' => 10,
                    'total_cached_tokens' => 5,
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

        expect($streamEnd->usage)
            ->inputTokens->toBe(42)
            ->outputTokens->toBe(10)
            ->cacheReadInputTokens->toBe(5);
    });

    test('streaming finish reason maps from the interaction status', function (string $status, FinishReason $expected): void {
        aiHttpFake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepDelta(0, 'text', 'Hello'),
                $this->interactionCompleted([], $status),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

        expect($streamEnd->reason)->toBe($expected->value);
    })->with([
        'completed maps to Stop' => ['completed', FinishReason::Stop],
        'incomplete maps to Length' => ['incomplete', FinishReason::Length],
    ]);
});
