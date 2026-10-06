<?php
declare(strict_types=1);

use Crustum\Ai\Responses\Data;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Streaming\Event\ToolResult as ToolResultEvent;

test('top level text and usage ignore the output a still running tool reported', function (): void {
    $response = new StreamableAgentResponse('invocation-1', fn(): Generator => yield from [
        new TextDelta('event-1', 'message-1', 'Hello', time()),
        new ToolResultEvent('event-2', new Data\ToolResult('call-1', 'document_specialist', [], 'internal'), true, null, time(), preliminary: true),
        new ToolResultEvent('event-3', new Data\ToolResult('call-1', 'document_specialist', [], 'internal monologue'), true, null, time(), preliminary: true),
        new TextDelta('event-4', 'message-1', ' world', time()),
        new StreamEnd('event-5', 'stop', new TextUsage(1, 2), time()),
    ], new Meta('fake', 'model'));

    iterator_to_array($response);

    expect($response->text)->toBe('Hello world')
        ->and($response->usage)->toEqual(new TextUsage(1, 2));
});

test('streamed response tool aggregates count a tool call once, not its preliminary output', function (): void {
    $events = collection([
        new TextDelta('event-1', 'message-1', 'Answer', time()),
        new ToolCallEvent('event-2', new Data\ToolCall('call-1', 'document_specialist', ['task' => 'Report']), time()),
        new ToolResultEvent('event-3', new Data\ToolResult('call-1', 'document_specialist', [], 'partial'), true, null, time(), preliminary: true),
        new ToolResultEvent('event-4', new Data\ToolResult('call-1', 'document_specialist', ['task' => 'Report'], 'done'), true, null, time()),
        new StreamEnd('event-5', 'stop', new TextUsage(1, 2), time()),
    ]);

    $response = new StreamedAgentResponse('invocation-1', $events, new Meta('fake', 'model'));

    expect($response->text)->toBe('Answer')
        ->and($response->toolCalls->map(fn(Data\ToolCall $call): string => $call->id)->toList())->toBe(['call-1'])
        ->and($response->toolResults->map(fn(Data\ToolResult $result): string => $result->id)->toList())->toBe(['call-1'])
        ->and($response->pendingApprovals)->toHaveCount(0);
});

test('a failure is reported to the catch callbacks once, however often the stream is re-iterated', function (): void {
    $response = new StreamableAgentResponse('invocation-1', function (): Generator {
        yield new TextDelta('event-1', 'message-1', 'Hello', time());

        throw new RuntimeException('Boom.');
    }, new Meta('fake', 'model'));

    $failures = [];

    $response->catch(function (Throwable $exception) use (&$failures): void {
        $failures[] = $exception->getMessage();
    });

    expect(fn(): array => iterator_to_array($response))->toThrow(RuntimeException::class);
    expect(fn(): array => iterator_to_array($response))->toThrow(RuntimeException::class);

    expect($failures)->toBe(['Boom.']);
});
