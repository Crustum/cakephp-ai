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
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.deepseek', [

        ...(array)Configure::read('Ai.providers.deepseek'),
        'key' => 'test-key',
    ]);
});

test('streaming emits text events', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                $this->chatChunk(['content' => ' world']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                '[DONE]',
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

test('streaming handles tool calls', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpSequence([
            aiHttpResponse(
                body: $this->ssePayload([
                    $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                    $this->chatChunkToolCallDelta(0, '{}'),
                    $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            aiHttpResponse(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent());

    $toolCallEvents = array_values(array_filter($events, fn($e): bool => $e instanceof ToolCallEvent));

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolCallEvents[0]->toolCall->id)->toBe('call_1');
});

test('streaming tool loop emits a single stream end with accumulated usage', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpSequence([
            aiHttpResponse(
                body: $this->ssePayload([
                    $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                    $this->chatChunkToolCallDelta(0, '{}'),
                    $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            aiHttpResponse(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                    '[DONE]',
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
        ->and($streamEnds[0]->usage->promptTokens)->toBe(30)
        ->and($streamEnds[0]->usage->completionTokens)->toBe(15);
});

test('streaming error event stops stream', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit exceeded']],
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

test('streaming captures usage from final chunk', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 42, 'completion_tokens' => 10]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(42)
        ->and($streamEnd->usage->completionTokens)->toBe(10);
});

test('streaming captures cache hit and reasoning tokens', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                $this->chatChunkFinish('stop', [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 50,
                    'prompt_cache_hit_tokens' => 30,
                    'prompt_cache_miss_tokens' => 70,
                    'total_tokens' => 150,
                    'completion_tokens_details' => [
                        'reasoning_tokens' => 12,
                    ],
                ]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(70)
        ->and($streamEnd->usage->completionTokens)->toBe(50)
        ->and($streamEnd->usage->cacheReadInputTokens)->toBe(30)
        ->and($streamEnd->usage->cacheWriteInputTokens)->toBe(0)
        ->and($streamEnd->usage->reasoningTokens)->toBe(12);
});

test('streaming finish reason maps correctly', function (string $apiReason, $expected): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                $this->chatChunkFinish($apiReason, ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                '[DONE]',
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
