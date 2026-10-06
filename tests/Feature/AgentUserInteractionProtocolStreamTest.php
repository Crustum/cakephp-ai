<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Crustum\Ai\Ai;
use Crustum\Ai\Approvals\ApprovalMismatchException;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Storage\DatabaseConversationStore;
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
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Streaming\Event\ToolCall;
use Crustum\Ai\Streaming\Event\ToolResult;
use Crustum\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingApprovableAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingAssistantAgent;
use Crustum\Ai\Test\Fixtures\FakeConversationStore;

beforeEach(function (): void {
    Ai::manager()->resetFakeState();
});

/**
 * Render a set of streaming events through the Agent User Interaction protocol and decode the emitted parts.
 *
 * @param array<int, \Crustum\Ai\Streaming\Event\StreamEvent>|\Closure $events Streaming events or a generator closure
 * @param string|null $threadId Optional thread id
 * @param string|null $runId Optional run id
 * @return array<int, array<string, mixed>>
 */
function agentUserInteractionProtocolEvents(array|Closure $events, ?string $threadId = 'thread-1', ?string $runId = 'run-1'): array
{
    $stream = $events instanceof Closure ? $events : fn() => yield from $events;

    return agentUserInteractionEvents((new StreamableAgentResponse('invocation-1', $stream, new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingAgentUserInteractionProtocol($threadId, $runId)
        ->toResponse());
}

/**
 * Decode the frames of an AG-UI protocol HTTP response.
 *
 * @param \Cake\Http\Response $response HTTP response
 * @return array<int, array<string, mixed>>
 */
function agentUserInteractionEvents(Response $response): array
{
    if (Configure::read('App.encoding') === null) {
        Configure::write('App.encoding', 'UTF-8');
    }

    ob_start();
    echo $response->getBody()->getContents();
    $output = (string)ob_get_clean();

    $frames = trim($output);

    if ($frames === '') {
        return [];
    }

    return collection(explode("\n\n", $frames))
        ->map(fn(string $frame): array => json_decode(str_replace('data: ', '', $frame), true))
        ->toList();
}

function agentUserInteractionRunFinished(string $reason = 'stop'): array
{
    return [
        'type' => 'RUN_FINISHED',
        'threadId' => 'thread-1',
        'runId' => 'run-1',
        'usage' => [[
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'inputTokens' => 0,
            'outputTokens' => 0,
            'totalTokens' => 0,
        ]],
        'metadata' => ['finishReason' => $reason],
    ];
}

test('a text stream emits run, step, and text message events', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new TextDelta('event-2', 'msg-1', 'Hello.', time()),
        new TextEnd('event-3', 'msg-1', time()),
        new StreamEnd('event-4', 'stop', new TextUsage(), time()),
    ]);

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'msg-1', 'role' => 'assistant'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg-1', 'delta' => 'Hello.'],
        ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'msg-1'],
        ['type' => 'STEP_FINISHED', 'stepName' => '1'],
        agentUserInteractionRunFinished(),
    ]);
});

test('the run finished event carries the combined usage and finish reason', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'length', new TextUsage(inputTokens: 10, outputTokens: 5, reasoningTokens: 2), time()),
    ]);

    expect(end($events))->toBe([
        'type' => 'RUN_FINISHED',
        'threadId' => 'thread-1',
        'runId' => 'run-1',
        'usage' => [[
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'inputTokens' => 10,
            'outputTokens' => 5,
            'totalTokens' => 15,
            'reasoningTokens' => 2,
        ]],
        'metadata' => ['finishReason' => 'length'],
    ]);
});

test('a multi step run combines the usage of every step', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'tool_calls', new TextUsage(inputTokens: 10, outputTokens: 5), time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-2', 'stop', new TextUsage(inputTokens: 20, outputTokens: 7), time()),
    ]);

    expect(end($events)['usage'][0])->toBe([
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'inputTokens' => 30,
        'outputTokens' => 12,
        'totalTokens' => 42,
    ])->and(end($events)['metadata'])->toBe(['finishReason' => 'stop']);
});

test('the response is served as an unbuffered event stream', function (): void {
    $response = (new StreamableAgentResponse('invocation-1', fn() => yield from [], new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingProtocol(new AgentUserInteractionProtocol())
        ->toResponse();

    expect($response->getHeaderLine('Content-Type'))->toBe('text/event-stream')
        ->and($response->getHeaderLine('Cache-Control'))->toContain('no-cache, no-transform');
});

test('a first turn stream emits the thread id the conversation is stored under', function (): void {
    Ai::manager()->setConversationStore(new FakeConversationStore());

    RememberingAssistantAgent::fake(['Fake response']);

    $user = new class {
        public int $id = 1;
    };

    $agent = (new RememberingAssistantAgent())->forUser($user);

    $events = agentUserInteractionEvents($agent->stream('Hello')->usingProtocol(new AgentUserInteractionProtocol())->toResponse());

    expect($agent->currentConversation())->not->toBeNull()
        ->and($events[0]['threadId'])->toBe($agent->currentConversation())
        ->and(end($events)['threadId'])->toBe($agent->currentConversation());
});

test('a remembered stream reports the assistant row it wrote', function (): void {
    Ai::manager()->setConversationStore(new FakeConversationStore());

    RememberingAssistantAgent::fake(['Fake response']);

    $user = new class {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent())->forUser($user)->stream('Hello');

    $events = agentUserInteractionEvents($response->usingProtocol(new AgentUserInteractionProtocol())->toResponse());
    $finished = end($events);

    expect($response->assistantMessageId)->not->toBeNull()
        ->and(array_keys($finished))->toBe(['type', 'threadId', 'runId', 'messageId', 'userMessageId', 'usage', 'metadata'])
        ->and($finished['threadId'])->toBe($response->conversationId)
        ->and($finished['runId'])->toBe($response->invocationId)
        ->and($finished['messageId'])->toBe($response->assistantMessageId);
});

test('a stored run reports the row it wrote for the prompt', function (): void {
    Ai::manager()->setConversationStore(new FakeConversationStore());

    RememberingAssistantAgent::fake(['Fake response']);

    $user = new class {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent())->forUser($user)->stream('Hello');

    $events = agentUserInteractionEvents($response->usingProtocol(new AgentUserInteractionProtocol())->toResponse());

    expect($response->userMessageId)->not->toBeNull()
        ->and(end($events)['userMessageId'])->toBe($response->userMessageId)
        ->and($response->userMessageId)->not->toBe($response->assistantMessageId);
});

test('a stream that persists nothing omits the message id', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new TextStart('event-1', 'msg-1', time()),
        new TextDelta('event-2', 'msg-1', 'Hello', time()),
        new TextEnd('event-3', 'msg-1', time()),
        new StreamEnd('event-4', 'stop', new TextUsage(), time()),
    ]);

    expect(end($events))->not->toHaveKey('messageId')
        ->and(end($events))->not->toHaveKey('userMessageId');
});

test('an ownerless approval stream persists the thread id it emits', function (): void {
    Ai::manager()->setConversationStore(new FakeConversationStore());

    RememberingApprovableAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'ApprovableNumberGenerator', [], 'Requires approval.'),
        ]),
    ]);

    $agent = new RememberingApprovableAgent();
    $events = agentUserInteractionEvents($agent->stream('Generate a number')->usingProtocol(new AgentUserInteractionProtocol())->toResponse());

    expect($events[0]['threadId'])->toBe($agent->currentConversation())
        ->and(end($events)['threadId'])->toBe($agent->currentConversation())
        ->and(end($events)['outcome']['type'])->toBe('interrupt');
});

test('a run without an explicit identity falls back to the conversation and invocation ids', function (): void {
    $response = (new StreamableAgentResponse('invocation-1', fn() => yield from [
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'stop', new TextUsage(), time()),
    ], new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->withinConversation('conversation-1')
        ->usingProtocol(new AgentUserInteractionProtocol());

    expect(agentUserInteractionEvents($response->toResponse())[0])->toBe([
        'type' => 'RUN_STARTED',
        'threadId' => 'conversation-1',
        'runId' => 'invocation-1',
    ]);
});

test('rendering the same response twice emits the same run', function (): void {
    $response = (new StreamableAgentResponse('invocation-1', fn() => yield from [
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ], new Data\Meta('anthropic', 'claude-sonnet-4-6')))->usingProtocol(new AgentUserInteractionProtocol());

    $render = fn(): array => agentUserInteractionEvents($response->toResponse());

    expect($render())->toBe($render());
});

test('a run without a conversation generates a thread id', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'stop', new TextUsage(), time()),
    ], threadId: null, runId: null);

    expect($events[0]['threadId'])->toBeString()->not->toBeEmpty()
        ->and($events[0]['runId'])->toBe('invocation-1');
});

test('a reasoning stream wraps the reasoning message in reasoning events', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ReasoningStart('event-1', 'reasoning-1', time()),
        new ReasoningDelta('event-2', 'reasoning-1', 'Considering the options.', time()),
        new ReasoningEnd('event-3', 'reasoning-1', time()),
        new StreamEnd('event-4', 'stop', new TextUsage(), time()),
    ]);

    expect(collection($events)->extract('type')->toList())->toBe([
        'RUN_STARTED', 'STEP_STARTED',
        'REASONING_START', 'REASONING_MESSAGE_START',
        'REASONING_MESSAGE_CONTENT',
        'REASONING_MESSAGE_END', 'REASONING_END',
        'STEP_FINISHED', 'RUN_FINISHED',
    ])->and($events[2])->toBe(['type' => 'REASONING_START', 'messageId' => 'reasoning-1'])
        ->and($events[3])->toBe(['type' => 'REASONING_MESSAGE_START', 'messageId' => 'reasoning-1', 'role' => 'reasoning'])
        ->and($events[4])->toBe(['type' => 'REASONING_MESSAGE_CONTENT', 'messageId' => 'reasoning-1', 'delta' => 'Considering the options.']);
});

test('a tool executed within the run emits its call and result events', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
        new StreamEnd('event-3', 'stop', new TextUsage(), time()),
    ]);

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'TOOL_CALL_START', 'toolCallId' => 'call-1', 'toolCallName' => 'GetWeather'],
        ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{"city":"Lisbon"}'],
        ['type' => 'TOOL_CALL_END', 'toolCallId' => 'call-1'],
        ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'event-2', 'toolCallId' => 'call-1', 'content' => 'sunny', 'role' => 'tool'],
        ['type' => 'STEP_FINISHED', 'stepName' => '1'],
        agentUserInteractionRunFinished(),
    ]);
});

test('preliminary tool output streams as an activity snapshot beside the terminal tool result', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'document_specialist', ['task' => 'Report']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'document_specialist', ['task' => 'Report'], 'internal monologue'), true, null, 200, preliminary: true),
        new ToolResult('event-3', new Data\ToolResult('call-1', 'document_specialist', ['task' => 'Report'], 'done'), true, null, time()),
        new StreamEnd('event-4', 'stop', new TextUsage(), time()),
    ]);

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'TOOL_CALL_START', 'toolCallId' => 'call-1', 'toolCallName' => 'document_specialist'],
        ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{"task":"Report"}'],
        ['type' => 'TOOL_CALL_END', 'toolCallId' => 'call-1'],
        [
            'type' => 'ACTIVITY_SNAPSHOT',
            'messageId' => 'call-1',
            'activityType' => 'TOOL_OUTPUT',
            'content' => [
                'toolName' => 'document_specialist',
                'output' => 'internal monologue',
            ],
        ],
        ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'event-3', 'toolCallId' => 'call-1', 'content' => 'done', 'role' => 'tool'],
        ['type' => 'STEP_FINISHED', 'stepName' => '1'],
        agentUserInteractionRunFinished(),
    ]);
});

test('a tool call without arguments streams an empty json object', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetTime', []), time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ]);

    expect($events[3])->toBe(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{}']);
});

test('a non string tool result is encoded as json content', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], ['temperature' => 21], resultId: 'result-1'), true, null, time()),
        new StreamEnd('event-3', 'stop', new TextUsage(), time()),
    ]);

    expect($events[5])->toBe([
        'type' => 'TOOL_CALL_RESULT',
        'messageId' => 'result-1',
        'toolCallId' => 'call-1',
        'content' => '{"temperature":21}',
        'role' => 'tool',
    ]);
});

test('a failed tool call streams its error as the result content', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], null), false, 'The city is unknown.', time()),
        new StreamEnd('event-3', 'stop', new TextUsage(), time()),
    ]);

    expect($events[5]['content'])->toBe('The city is unknown.');
});

test('a failed tool call without an error message streams a default result content', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], null), false, null, time()),
        new StreamEnd('event-3', 'stop', new TextUsage(), time()),
    ]);

    expect($events[5]['content'])->toBe('The tool call failed.');
});

test('a multi step run wraps each provider step in step events', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new StreamEnd('event-2', 'tool_calls', new TextUsage(), time()),
        new ToolResult('event-3', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-4', 'msg-2', 'Sunny.', time()),
        new StreamEnd('event-5', 'stop', new TextUsage(), time()),
    ]);

    expect(collection($events)->extract('type')->toList())->toBe([
        'RUN_STARTED', 'STEP_STARTED',
        'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'TOOL_CALL_RESULT',
        'STEP_FINISHED', 'STEP_STARTED',
        'TEXT_MESSAGE_CONTENT',
        'STEP_FINISHED', 'RUN_FINISHED',
    ])->and(collection($events)->filter(fn(array $event): bool => in_array($event['type'], ['STEP_STARTED', 'STEP_FINISHED'], true))->extract('stepName')->toList())
        ->toBe(['1', '1', '2', '2']);
});

test('a paused run finishes with an interrupt outcome for each pending approval', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
        new ToolApprovalRequest('event-2', collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Destructive operation.'),
        ]), time()),
        new StreamEnd('event-3', 'tool_calls', new TextUsage(), time()),
    ]);

    expect(end($events))->toBe([
        'type' => 'RUN_FINISHED',
        'threadId' => 'thread-1',
        'runId' => 'run-1',
        'outcome' => [
            'type' => 'interrupt',
            'interrupts' => [[
                'id' => 'call-1',
                'reason' => 'approval_required',
                'message' => 'Destructive operation.',
                'toolCallId' => 'call-1',
                'metadata' => [
                    'kind' => 'approval',
                    'toolName' => 'DeleteFile',
                    'input' => ['path' => 'a.txt'],
                ],
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => ['approved' => ['type' => 'boolean']],
                    'required' => ['approved'],
                ],
            ]],
        ],
    ]);
});

test('a paused run reports its interrupt outcome even when the stream later throws', function (): void {
    Log::setConfig('agui_test', ['className' => ArrayLog::class, 'levels' => ['error']]);

    try {
        $events = agentUserInteractionProtocolEvents(function () {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new ToolApprovalRequest('event-1', collect([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Destructive operation.'),
            ]), time());

            throw new RuntimeException('The conversation could not be persisted.');
        });

        expect(collection($events)->extract('type')->toList())->toBe([
            'RUN_STARTED', 'STEP_STARTED', 'STEP_FINISHED', 'RUN_FINISHED',
        ])->and($events[3]['outcome']['interrupts'][0]['id'])->toBe('call-1');

        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine('agui_test');

        expect($engine->read())->toHaveCount(1);
    } finally {
        Log::drop('agui_test');
    }
});

test('a resume the store rejects mid-stream ends the real stream with the mismatch code', function (): void {
    Configure::write('Ai.conversations.generate_title', false);
    Configure::write('Ai.conversations.connection', 'test');
    Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
    Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');

    Ai::manager()->setConversationStore(new DatabaseConversationStore());

    Log::setConfig('agui_test', ['className' => ArrayLog::class, 'levels' => ['error']]);

    try {
        aiHttpFake([
            'api.anthropic.com/*' => aiHttpSequence([
                aiHttpResponse([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object)[],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                aiHttpResponse([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $paused = (new RememberingApprovableAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])
            ->prompt('Generate a number', provider: 'anthropic');

        Ai::manager()->setConversationStore(new class extends DatabaseConversationStore {
            public function storeApprovalResults(string $conversationId, array $toolResults): void
            {
                throw new ApprovalMismatchException('The approval results do not match a paused conversation turn.', collection([]));
            }
        });

        $events = agentUserInteractionEvents((new RememberingApprovableAgent())
            ->continue($paused->conversationId, (object)['id' => '00000000-0000-0000-0000-000000000001'])
            ->stream(Decisions::from(['toolu_1' => true]), provider: 'anthropic')
            ->usingAgentUserInteractionProtocol('thread-1', 'run-1')
            ->toResponse());

        expect(collection($events)->extract('type')->toList())->toBe(['RUN_STARTED', 'STEP_STARTED', 'RUN_ERROR'])
            ->and($events[2])->toBe([
                'type' => 'RUN_ERROR',
                'message' => 'The approval results do not match a paused conversation turn.',
                'code' => 'approval_mismatch',
            ]);

        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine('agui_test');

        expect($engine->read())->toHaveCount(1);
    } finally {
        Log::drop('agui_test');
    }
});

test('a pending approval without a reason omits the interrupt message', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolApprovalRequest('event-1', collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt']),
        ]), time()),
        new StreamEnd('event-2', 'tool_calls', new TextUsage(), time()),
    ]);

    expect(end($events)['outcome']['interrupts'][0])->not->toHaveKey('message');
});

test('a resumed run emits the approved tool result for the prior turn tool call', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-2', 'msg-2', 'Done.', time()),
        new StreamEnd('event-3', 'stop', new TextUsage(), time()),
    ]);

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'event-1', 'toolCallId' => 'call-1', 'content' => 'deleted', 'role' => 'tool'],
        ['type' => 'STEP_FINISHED', 'stepName' => '1'],
        ['type' => 'STEP_STARTED', 'stepName' => '2'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg-2', 'delta' => 'Done.'],
        ['type' => 'STEP_FINISHED', 'stepName' => '2'],
        agentUserInteractionRunFinished(),
    ]);
});

test('a resumed run streams the replayed tool result without restating its call', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ], threadId: null, runId: null);

    expect(collection($events)->extract('type')->toList())->toBe([
        'RUN_STARTED', 'STEP_STARTED', 'TOOL_CALL_RESULT', 'STEP_FINISHED', 'RUN_FINISHED',
    ]);
});

test('a rejected approval streams the rejection as the tool result content', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The user rejected this tool call.'), false, 'The user rejected this tool call.', time(), denied: true),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ]);

    expect($events[2])->toBe([
        'type' => 'TOOL_CALL_RESULT',
        'messageId' => 'event-1',
        'toolCallId' => 'call-1',
        'content' => 'The user rejected this tool call.',
        'role' => 'tool',
        'metadata' => ['error' => 'The user rejected this tool call.', 'denied' => true],
    ]);
});

test('a failed tool call reports an error without marking it denied', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The tool call failed: disk unavailable.'), false, 'The tool call failed: disk unavailable.', time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ]);

    expect($events[2])->toBe([
        'type' => 'TOOL_CALL_RESULT',
        'messageId' => 'event-1',
        'toolCallId' => 'call-1',
        'content' => 'The tool call failed: disk unavailable.',
        'role' => 'tool',
        'metadata' => ['error' => 'The tool call failed: disk unavailable.'],
    ]);
});

test('a cited text stream emits a custom citation event', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Citation('event-1', 'msg-1', new Data\UrlCitation('https://cakephp.org/docs', 'CakePHP Documentation'), time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ]);

    expect($events[2])->toBe([
        'type' => 'CUSTOM',
        'name' => 'citation',
        'value' => ['url' => 'https://cakephp.org/docs', 'title' => 'CakePHP Documentation'],
    ]);
});

test('a url citation without a title omits only the title from the custom event', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Citation('event-1', 'msg-1', new Data\UrlCitation('https://cakephp.org/docs'), time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ]);

    expect($events[2]['value'])->toBe(['url' => 'https://cakephp.org/docs']);
});

test('an unknown citation type is skipped instead of ending the run', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Citation('event-1', 'msg-1', new class extends Data\Citation {
            public function toArray(): array
            {
                return ['type' => 'unknown'];
            }
        }, time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ]);

    expect(collection($events)->extract('type')->toList())->toBe([
        'RUN_STARTED', 'STEP_STARTED', 'STEP_FINISHED', 'RUN_FINISHED',
    ]);
});

test('a provider hosted tool emits a custom provider tool event', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ProviderToolEvent('event-1', 'item-1', 'web_search_call', ['query' => 'cakephp'], 'completed', time(), 'anthropic'),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ]);

    expect($events[2])->toBe([
        'type' => 'CUSTOM',
        'name' => 'provider-tool',
        'value' => [
            'provider' => 'anthropic',
            'itemId' => 'item-1',
            'type' => 'web_search_call',
            'data' => ['query' => 'cakephp'],
            'status' => 'completed',
        ],
    ]);
});

test('events streamed after an error are dropped because the run has ended', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Error('event-1', 'overloaded_error', 'Overloaded', false, time()),
        new TextDelta('event-2', 'msg-1', 'Ghost.', time()),
        new StreamEnd('event-3', 'stop', new TextUsage(), time()),
    ]);

    expect(collection($events)->extract('type')->toList())->toBe([
        'RUN_STARTED', 'STEP_STARTED', 'RUN_ERROR',
    ]);
});

test('the thread id adopts a conversation id surfaced after streaming begins', function (): void {
    $response = null;

    $response = new StreamableAgentResponse('invocation-1', function () use (&$response) {
        $response->withinConversation('conversation-9');

        yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
        yield new StreamEnd('event-1', 'stop', new TextUsage(), time());
    }, new Data\Meta('anthropic', 'claude-sonnet-4-6'));

    $events = agentUserInteractionEvents($response->usingProtocol(new AgentUserInteractionProtocol())->toResponse());

    expect($events[0])->toBe([
        'type' => 'RUN_STARTED',
        'threadId' => 'conversation-9',
        'runId' => 'invocation-1',
    ]);
});

test('a failed run emits a run error instead of a run finished event', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new Error('event-2', 'overloaded_error', 'Overloaded', false, time()),
    ]);

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'msg-1', 'role' => 'assistant'],
        ['type' => 'RUN_ERROR', 'message' => 'Overloaded', 'code' => 'overloaded_error'],
    ]);
});

test('a stream end after an error does not finish the run', function (): void {
    $events = agentUserInteractionProtocolEvents([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Error('event-1', 'overloaded_error', 'Overloaded', false, time()),
        new StreamEnd('event-2', 'error', new TextUsage(), time()),
    ]);

    expect(collection($events)->extract('type')->toList())->toBe([
        'RUN_STARTED', 'STEP_STARTED', 'RUN_ERROR',
    ]);
});

test('an exception mid run is reported and emitted as a masked run error', function (): void {
    Log::setConfig('agui_test', ['className' => ArrayLog::class, 'levels' => ['error']]);

    try {
        $events = agentUserInteractionProtocolEvents(function () {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new TextDelta('event-1', 'msg-1', 'Hel', time());

            throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        });

        expect($events)->toBe([
            ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
            ['type' => 'STEP_STARTED', 'stepName' => '1'],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg-1', 'delta' => 'Hel'],
            ['type' => 'RUN_ERROR', 'message' => 'An error occurred.'],
        ]);

        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine('agui_test');

        expect($engine->read())->toHaveCount(1);
    } finally {
        Log::drop('agui_test');
    }
});

test('a provider stream error followed by the loop exception emits a single run error', function (): void {
    $events = agentUserInteractionProtocolEvents(function () {
        yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());

        $error = new Error('event-1', 'overloaded_error', 'Overloaded', false, time());

        yield $error;

        throw new StreamErrorException($error);
    });

    expect($events)->toBe([
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepName' => '1'],
        ['type' => 'RUN_ERROR', 'message' => 'Overloaded', 'code' => 'overloaded_error'],
    ]);
});

test('an unexpected exception after a run error is still reported', function (): void {
    Log::setConfig('agui_test', ['className' => ArrayLog::class, 'levels' => ['error']]);

    try {
        $events = agentUserInteractionProtocolEvents(function () {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new Error('event-1', 'overloaded_error', 'Overloaded', false, time());

            throw new RuntimeException('Broken pipe');
        });

        expect(collection($events)->match(['type' => 'RUN_ERROR'])->count())->toBe(1);

        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine('agui_test');

        expect($engine->read())->toHaveCount(1);
    } finally {
        Log::drop('agui_test');
    }
});

test('a faked multi step agent stream emits a well formed run', function (): void {
    MultiStepToolAgent::fake([
        new Data\ToolCall('call-1', 'FixedNumberGenerator', []),
        'The number is 72019.',
    ]);

    $response = (new MultiStepToolAgent())->stream('Generate a number')
        ->usingProtocol(new AgentUserInteractionProtocol('thread-1', 'run-1'))
        ->toResponse();

    $types = collection(agentUserInteractionEvents($response))->extract('type')->toList();

    $collapsed = [];

    foreach ($types as $type) {
        if (end($collapsed) !== $type) {
            $collapsed[] = $type;
        }
    }

    expect($collapsed)->toBe([
        'RUN_STARTED', 'STEP_STARTED',
        'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'TOOL_CALL_RESULT',
        'STEP_FINISHED', 'STEP_STARTED',
        'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END',
        'STEP_FINISHED', 'RUN_FINISHED',
    ]);
});

test('an exception before the run starts emits a masked run error within a started run', function (): void {
    Log::setConfig('agui_test', ['className' => ArrayLog::class, 'levels' => ['error']]);

    try {
        $events = agentUserInteractionProtocolEvents(function (): Generator {
            throw new RuntimeException('Broken pipe');
        });

        expect($events)->toBe([
            ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
            ['type' => 'STEP_STARTED', 'stepName' => '1'],
            ['type' => 'RUN_ERROR', 'message' => 'An error occurred.'],
        ]);

        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine('agui_test');

        expect($engine->read())->toHaveCount(1);
    } finally {
        Log::drop('agui_test');
    }
});
