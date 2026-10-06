<?php
declare(strict_types=1);

use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextStart;

beforeEach(function (): void {
    configureOpenAiCompatible();
});

test('prompt reads reasoning off the response', function (string $field): void {
    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'local-model',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello', $field => 'Let me think...'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ])]);

    $response = agent()->prompt('Hi', provider: 'openai-compatible');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
})->with(['reasoning_content', 'reasoning']);

test('streaming emits reasoning events before the text', function (string $field): void {
    $chunk = fn(array $delta, ?string $finish = null): string => 'data: ' . json_encode([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion.chunk',
        'model' => 'local-model',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
    ]);

    aiHttpFake(['*' => aiHttpResponse(implode("\n\n", [
        $chunk(['role' => 'assistant', $field => 'Let me ']),
        $chunk([$field => 'think...']),
        $chunk(['content' => 'Hello']),
        $chunk([], 'stop'),
        'data: [DONE]',
    ]) . "\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

    $events = [];

    foreach (agent()->stream('Hi', provider: 'openai-compatible') as $event) {
        $events[] = $event;
    }

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and(ReasoningDelta::combine($events))->toBe('Let me think...');
})->with(['reasoning_content', 'reasoning']);
