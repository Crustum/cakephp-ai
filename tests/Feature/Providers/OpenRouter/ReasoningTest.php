<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

function fakeOpenRouterReasonedResponse(array $message): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello', ...$message],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ]);
}

test('prompt reads the plaintext reasoning off the response', function (): void {
    aiHttpFake(['*' => fakeOpenRouterReasonedResponse(['reasoning' => 'Let me think...'])]);

    $response = (new AssistantAgent())->prompt('Hi', provider: 'openrouter');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
});

test('prompt falls back to the reasoning details when no plaintext reasoning is sent', function (): void {
    aiHttpFake(['*' => fakeOpenRouterReasonedResponse(['reasoning_details' => [
        ['type' => 'reasoning.text', 'text' => 'First.', 'index' => 0],
        ['type' => 'reasoning.summary', 'summary' => 'Second.', 'index' => 1],
        ['type' => 'reasoning.encrypted', 'data' => 'ciphertext', 'index' => 2],
    ]])]);

    expect((new AssistantAgent())->prompt('Hi', provider: 'openrouter')->reasoning)->toBe("First.\n\nSecond.");
});

test('streaming emits reasoning events', function (): void {
    aiHttpFake(['*' => aiHttpResponse(
        body: $this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Let me ', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'Let me ']]]),
            $this->chatChunk(['reasoning' => 'think...', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'think...', 'signature' => 'sig']]]),
            $this->chatChunk(['content' => 'Hello']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
        ]),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    )]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and(ReasoningDelta::combine($events))->toBe('Let me think...');
});

test('streamed reasoning details drive the reasoning events when no plaintext reasoning is sent', function (): void {
    aiHttpFake(['*' => aiHttpResponse(
        body: $this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'Let me ']]]),
            $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'think...']]]),
            $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.summary', 'index' => 1, 'summary' => 'I decided.']]]),
            $this->chatChunk(['content' => 'Hello']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
        ]),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    )]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('I decided.')
        ->and($events[5])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[6])->toBeInstanceOf(TextStart::class)
        ->and(ReasoningDelta::combine($events))->toBe('Let me think...I decided.');
});

test('an encrypted reasoning detail drives no reasoning events', function (): void {
    aiHttpFake(['*' => aiHttpResponse(
        body: $this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.encrypted', 'index' => 0, 'data' => 'ciphertext']]]),
            $this->chatChunk(['content' => 'Hello']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
        ]),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    )]);

    $events = $this->collectStreamEvents();

    expect(collect($events)->filter(fn($event): bool => $event instanceof ReasoningStart))->toBeEmpty()
        ->and(ReasoningDelta::combine($events))->toBe('');
});
