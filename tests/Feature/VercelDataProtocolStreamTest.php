<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Responses\Data;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Streaming\Event\Citation;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\ReasoningEnd;
use Crustum\Ai\Streaming\Event\ReasoningStart;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Streaming\Event\ToolCall;
use Crustum\Ai\Streaming\Event\ToolResult;
use Crustum\Ai\Streaming\Protocols\VercelDataProtocol;
use Crustum\Ai\Vercel\Vercel;

/**
 * Render a set of streaming events through the Vercel protocol and decode the emitted parts.
 *
 * @param array<int, \Crustum\Ai\Streaming\Event\StreamEvent>|\Closure $events Streaming events or a generator closure
 * @param string|null $messageId Optional client message id
 * @param \Crustum\Ai\Streaming\Protocols\VercelDataProtocol|null $protocol Optional protocol instance
 * @return array<int, array<string, mixed>>
 */
function vercelProtocolParts(array|Closure $events, ?string $messageId = null, ?VercelDataProtocol $protocol = null): array
{
    if (Configure::read('App.encoding') === null) {
        Configure::write('App.encoding', 'UTF-8');
    }

    $stream = $events instanceof Closure ? $events : fn() => yield from $events;

    $response = (new StreamableAgentResponse('invocation-1', $stream, new Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingProtocol($protocol ?? new VercelDataProtocol($messageId))
        ->toResponse();

    ob_start();
    echo $response->getBody()->getContents();
    $output = (string)ob_get_clean();

    return collection(explode("\n\n", trim($output)))
        ->map(fn(string $frame): string => str_replace('data: ', '', $frame))
        ->map(fn(string $payload): array => $payload === '[DONE]' ? ['type' => 'done'] : json_decode($payload, true))
        ->toList();
}

/**
 * Build the expected finish part for the stream.
 *
 * @param string $reason Finish reason
 * @param \Crustum\Ai\Responses\Data\TextUsage|null $usage Token usage
 * @return array<string, mixed>
 */
function vercelFinishPart(string $reason = 'stop', ?TextUsage $usage = null): array
{
    $usage ??= new TextUsage();

    return [
        'type' => 'finish',
        'finishReason' => $reason,
        'messageMetadata' => [
            'usage' => [
                'inputTokens' => $usage->inputTokens,
                'outputTokens' => $usage->outputTokens,
                'totalTokens' => $usage->inputTokens + $usage->outputTokens,
                'reasoningTokens' => $usage->reasoningTokens,
                'cachedInputTokens' => $usage->cacheReadInputTokens,
            ],
        ],
    ];
}

test('a text stream emits start, delta, and end parts for the message', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new TextDelta('event-2', 'msg-1', 'Hello.', time()),
        new TextEnd('event-3', 'msg-1', time()),
        new StreamEnd('event-4', 'stop', new TextUsage(), time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'text-start', 'id' => 'msg-1'],
        ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hello.'],
        ['type' => 'text-end', 'id' => 'msg-1'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a reasoning stream emits start, delta, and end parts for the reasoning block', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ReasoningStart('event-1', 'reasoning-1', time()),
        new ReasoningDelta('event-2', 'reasoning-1', 'Considering the options.', time()),
        new ReasoningEnd('event-3', 'reasoning-1', time()),
        new StreamEnd('event-4', 'stop', new TextUsage(), time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'reasoning-start', 'id' => 'reasoning-1'],
        ['type' => 'reasoning-delta', 'id' => 'reasoning-1', 'delta' => 'Considering the options.'],
        ['type' => 'reasoning-end', 'id' => 'reasoning-1'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a cited text stream emits a source url part for the cited page', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new TextDelta('event-2', 'msg-1', 'CakePHP is a PHP framework.', time()),
        new Citation('event-3', 'msg-1', new Data\UrlCitation('https://book.cakephp.org/5.x', 'CakePHP Documentation'), time()),
        new TextEnd('event-4', 'msg-1', time()),
        new StreamEnd('event-5', 'stop', new TextUsage(), time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'text-start', 'id' => 'msg-1'],
        ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'CakePHP is a PHP framework.'],
        ['type' => 'source-url', 'sourceId' => 'https://book.cakephp.org/5.x', 'url' => 'https://book.cakephp.org/5.x', 'title' => 'CakePHP Documentation'],
        ['type' => 'text-end', 'id' => 'msg-1'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a url citation without a title omits only the title from the source url part', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Citation('event-1', 'msg-1', new Data\UrlCitation('https://book.cakephp.org/5.x'), time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ]);

    expect($parts[2])->toBe([
        'type' => 'source-url',
        'sourceId' => 'https://book.cakephp.org/5.x',
        'url' => 'https://book.cakephp.org/5.x',
    ]);
});

test('an unknown citation type is skipped instead of ending the stream', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Citation('event-1', 'msg-1', new class extends Data\Citation
        {
            public function toArray(): array
            {
                return [];
            }
        }, time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a failed stream emits an error part instead of a finish part', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new Error('event-2', 'overloaded_error', 'Overloaded', false, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'text-start', 'id' => 'msg-1'],
        ['type' => 'error', 'errorText' => 'Overloaded'],
        ['type' => 'done'],
    ]);
});

test('a paused stream emits an approval request part for each pending approval', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
        new ToolApprovalRequest('event-2', collection([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Destructive operation.'),
        ]), time()),
        new StreamEnd('event-3', 'tool_calls', new TextUsage(), time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'DeleteFile', 'input' => ['path' => 'a.txt']],
        ['type' => 'tool-approval-request', 'toolCallId' => 'call-1', 'approvalId' => 'call-1', 'reason' => 'Destructive operation.'],
        ['type' => 'finish-step'],
        vercelFinishPart('tool-calls'),
        ['type' => 'done'],
    ]);
});

test('a resumed stream emits the approved tool output for the prior turn tool call', function (): void {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-2', 'msg-2', 'Done.', time()),
        new StreamEnd('event-3', 'stop', new TextUsage(), time()),
    ], messageId: 'client-message-1');

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'client-message-1'],
        ['type' => 'start-step'],
        ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'deleted'],
        ['type' => 'finish-step'],
        ['type' => 'start-step'],
        ['type' => 'text-delta', 'id' => 'msg-2', 'delta' => 'Done.'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a resumed stream may continue an existing client-side message', function (): void {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ], messageId: 'client-message-1');

    expect($parts[0])->toBe(['type' => 'start', 'messageId' => 'client-message-1'])
        ->and(collection($parts)->filter(fn(array $part): bool => $part['type'] === 'start')->toList())->toHaveCount(1);
});

test('a resumed chat streams the approved tool output into its assistant message', function (): void {
    $chat = Vercel::chat([
        ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete a.txt']]],
        ['id' => 'm2', 'role' => 'assistant', 'parts' => [
            ['type' => 'tool-DeleteFile', 'toolCallId' => 'call-1', 'state' => 'approval-responded', 'input' => ['path' => 'a.txt'], 'approval' => ['id' => 'call-1', 'approved' => true]],
        ]],
    ]);

    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ], protocol: $chat->protocol());

    expect($parts[0])->toBe(['type' => 'start', 'messageId' => 'm2'])
        ->and($parts[2])->toBe(['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'deleted']);
});

test('a rejected approval streams as a denied tool output', function (): void {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The user rejected this tool call.'), false, 'The user rejected this tool call.', time(), denied: true),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ], messageId: 'client-message-1');

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'client-message-1'],
        ['type' => 'start-step'],
        ['type' => 'tool-output-denied', 'toolCallId' => 'call-1'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a tool result without a prior call or existing message is skipped', function (): void {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'done'],
    ]);
});

test('an unexecuted tool call streams as a tool output error', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The agent reached its maximum number of steps without running this tool call.'), false, 'The agent reached its maximum number of steps without running this tool call.', time()),
        new StreamEnd('event-3', 'stop', new TextUsage(), time()),
    ]);

    expect($parts[3])->toBe([
        'type' => 'tool-output-error',
        'toolCallId' => 'call-1',
        'errorText' => 'The agent reached its maximum number of steps without running this tool call.',
    ]);
});

test('a failed tool call without an error message streams a default error text', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], null), false, null, time()),
        new StreamEnd('event-3', 'stop', new TextUsage(), time()),
    ]);

    expect($parts[3])->toBe([
        'type' => 'tool-output-error',
        'toolCallId' => 'call-1',
        'errorText' => 'The tool call failed.',
    ]);
});

test('the finish part carries the stream usage and finish reason as message metadata', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-1', 'msg-1', 'Hello.', time()),
        new StreamEnd('event-2', 'length', new TextUsage(inputTokens: 10, outputTokens: 20), time()),
    ]);

    expect($parts[count($parts) - 2])->toBe(vercelFinishPart('length', new TextUsage(inputTokens: 10, outputTokens: 20)));
});

test('finish reasons outside the Vercel enum emit as other', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'continue', new TextUsage(), time()),
    ]);

    expect($parts[count($parts) - 2])->toBe(vercelFinishPart('other'));
});

test('an unknown finish reason maps to other', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'unknown', new TextUsage(), time()),
    ]);

    expect($parts[count($parts) - 2])->toBe(vercelFinishPart('other'));
});

test('a multi-step stream emits one finish part with combined usage and the final reason', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new StreamEnd('event-2', 'tool_calls', new TextUsage(inputTokens: 10, outputTokens: 5), time()),
        new ToolResult('event-3', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-4', 'msg-1', 'Sunny.', time()),
        new StreamEnd('event-5', 'stop', new TextUsage(inputTokens: 20, outputTokens: 15), time()),
    ]);

    expect(collection($parts)->filter(fn(array $part): bool => $part['type'] === 'finish')->values()->toList())->toBe([
        vercelFinishPart('stop', new TextUsage(inputTokens: 30, outputTokens: 20)),
    ])->and(collection($parts)->map(fn(array $part): string => $part['type'])->toList())->toBe([
        'start', 'start-step',
        'tool-input-available', 'tool-output-available', 'finish-step',
        'start-step', 'text-delta', 'finish-step',
        'finish', 'done',
    ]);
});

test('an exception mid-stream is reported and emitted as a masked terminal error part', function (): void {
    Log::setConfig('vercel_test', ['className' => ArrayLog::class, 'levels' => ['error']]);

    try {
        $parts = vercelProtocolParts(function () {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new TextDelta('event-1', 'msg-1', 'Hel', time());

            throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        });

        expect($parts)->toBe([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hel'],
            ['type' => 'error', 'errorText' => 'An error occurred.', 'errorCode' => 'stream_error'],
            ['type' => 'done'],
        ]);

        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine('vercel_test');

        expect($engine->read())->toHaveCount(1)
            ->and(implode(' ', $engine->read()))->toContain('Stream failed');
    } finally {
        Log::drop('vercel_test');
    }
});

test('a provider stream error followed by the loop exception emits a single error part', function (): void {
    Log::setConfig('vercel_test', ['className' => ArrayLog::class, 'levels' => ['error']]);

    try {
        $parts = vercelProtocolParts(function () {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new TextDelta('event-1', 'msg-1', 'Hel', time());

            $error = new Error('event-2', 'overloaded_error', 'Overloaded', false, time());

            yield $error;

            throw new StreamErrorException($error);
        });

        expect($parts)->toBe([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hel'],
            ['type' => 'error', 'errorText' => 'Overloaded'],
            ['type' => 'done'],
        ]);

        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine('vercel_test');

        expect($engine->read())->toHaveCount(0);
    } finally {
        Log::drop('vercel_test');
    }
});

test('an unexpected exception after an error part is still reported', function (): void {
    Log::setConfig('vercel_test', ['className' => ArrayLog::class, 'levels' => ['error']]);

    try {
        $parts = vercelProtocolParts(function () {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new Error('event-1', 'overloaded_error', 'Overloaded', false, time());

            throw new RuntimeException('Broken pipe');
        });

        expect(collection($parts)->filter(fn(array $part): bool => $part['type'] === 'error')->toList())->toHaveCount(1);

        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine('vercel_test');

        expect($engine->read())->toHaveCount(1);
    } finally {
        Log::drop('vercel_test');
    }
});

test('a stream end after an error does not emit finish parts', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Error('event-1', 'overloaded_error', 'Overloaded', false, time()),
        new StreamEnd('event-2', 'error', new TextUsage(), time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'error', 'errorText' => 'Overloaded'],
        ['type' => 'done'],
    ]);
});

test('a tool executed within the stream emits its input and output parts', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
        new StreamEnd('event-3', 'stop', new TextUsage(), time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'GetWeather', 'input' => ['city' => 'Lisbon']],
        ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'sunny'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a tool still producing its output emits preliminary parts without changing the run lifecycle', function (): void {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-1', 'msg-1', 'Working on it.', time()),
        new ToolCall('event-2', new Data\ToolCall('call-1', 'document_specialist', ['task' => 'Report']), time()),
        new ToolResult('event-3', new Data\ToolResult('call-1', 'document_specialist', ['task' => 'Report'], 'internal monologue'), true, null, 200, preliminary: true),
        new ToolResult('event-4', new Data\ToolResult('call-1', 'document_specialist', ['task' => 'Report'], 'done'), true, null, time()),
        new TextDelta('event-5', 'msg-1', ' Done.', time()),
        new StreamEnd('event-6', 'stop', new TextUsage(), time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Working on it.'],
        ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'document_specialist', 'input' => ['task' => 'Report']],
        [
            'type' => 'tool-output-available',
            'toolCallId' => 'call-1',
            'output' => 'internal monologue',
            'preliminary' => true,
        ],
        ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'done'],
        ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => ' Done.'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});
