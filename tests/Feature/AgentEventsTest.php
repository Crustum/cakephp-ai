<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Event\AgentFailed;
use Crustum\Ai\Event\AgentFailedOver;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\AgentStreamed;
use Crustum\Ai\Event\InvokingTool;
use Crustum\Ai\Event\PromptingAgent;
use Crustum\Ai\Event\StartingStep;
use Crustum\Ai\Event\StepCompleted;
use Crustum\Ai\Event\StepFailed;
use Crustum\Ai\Event\ToolFailed;
use Crustum\Ai\Event\ToolInvoked;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Exception\StreamErrorException;
use Crustum\Ai\Gateway\ParentInvocation;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\PendingStep;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Providers\GroqProvider;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Streaming\Event\Error;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\DelegatingAgent;
use Crustum\Ai\Test\Fixtures\Agents\DelegatingViaCustomToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\MiddleManagerAgent;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\OrchestratorAgent;
use Crustum\Ai\Test\Fixtures\Agents\RateLimitedToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingAssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingThrowingApprovableAgent;
use Crustum\Ai\Test\Fixtures\Agents\ResearchAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Fixtures\FakeConversationStore;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\TestSuite\AiFlow;
use Crustum\Ai\Tools\AgentTool;

function agentEventsGroqProviders(array $names): void
{
    foreach ($names as $name) {
        Configure::write("Ai.providers.{$name}", [
            'className' => GroqProvider::class,
            'driver' => 'groq',
            'key' => 'test-key',
            'name' => $name,
        ]);
    }
}

function agentEventsGroqToolCall(string $name, array $arguments = [], string $id = 'call_123'): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $id,
                    'type' => 'function',
                    'function' => [
                        'name' => $name,
                        'arguments' => $arguments === [] ? '{}' : json_encode($arguments),
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}

function agentEventsGroqStreamChunk(array|object $delta, ?string $finishReason = null): array
{
    return [
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion.chunk',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
    ];
}

function agentEventsSseBody(array $frames): AiHttpResponseDefinition
{
    $lines = array_map(fn($frame): string => 'data: ' . json_encode($frame), $frames);
    $lines[] = 'data: [DONE]';

    return aiHttpResponse(implode("\n\n", $lines) . "\n\n", 200, ['Content-Type' => 'text/event-stream']);
}

function agentEventsGroqStream(string $text): AiHttpResponseDefinition
{
    return agentEventsSseBody([
        agentEventsGroqStreamChunk(['role' => 'assistant', 'content' => $text]),
        agentEventsGroqStreamChunk((object)[], 'stop'),
    ]);
}

function agentEventsGroqStreamError(string $message): AiHttpResponseDefinition
{
    return agentEventsSseBody([
        ['error' => ['code' => 'server_error', 'message' => $message]],
    ]);
}

function agentEventsGroqStreamToolCall(string $name, string $id = 'call_123'): AiHttpResponseDefinition
{
    return agentEventsSseBody([
        agentEventsGroqStreamChunk(['role' => 'assistant', 'tool_calls' => [[
            'index' => 0,
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => ''],
        ]]]),
        agentEventsGroqStreamChunk(['tool_calls' => [[
            'index' => 0,
            'function' => ['arguments' => '{}'],
        ]]]),
        agentEventsGroqStreamChunk((object)[], 'tool_calls'),
    ]);
}

/**
 * @template T of object
 * @param class-string<T> $class
 * @return list<T>
 */
function agentEventsOf(string $class): array
{
    return collect(AiFlow::getAiEvents())->filter(fn($event): bool => $event instanceof $class)->toList();
}

test('a synchronous prompt threads one invocation id through the prompt and its events', function (): void {
    AssistantAgent::fake(['Hello!']);

    $response = (new AssistantAgent())->prompt('Hi');

    $this->assertAiEventDispatched(PromptingAgent::class, fn(PromptingAgent $event): bool => $event->invocationId === $response->invocationId
        && $event->prompt->invocationId === $response->invocationId);

    $this->assertAiEventDispatched(AgentPrompted::class, fn(AgentPrompted $event): bool => $event->invocationId === $response->invocationId);
});

test('step middleware receives the invocation id the run reports', function (): void {
    AssistantAgent::fake(['Hello!']);

    $seen = null;

    $response = (new AssistantAgent())->withMiddleware([
        function (PendingStep $step, Closure $next) use (&$seen) {
            $seen = $step->invocationId;

            return $next($step);
        },
    ])->prompt('Hi');

    expect($seen)->not->toBeNull()->toBe($response->invocationId);
});

test('every failover attempt shares the run invocation id', function (): void {
    agentEventsGroqProviders(['primary', 'backup']);

    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
            fakeGroqResponse('Hello from the backup.'),
        ]),
    ]);

    $response = (new AssistantAgent())->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello from the backup.');

    $this->assertAiEventDispatched(AgentFailedOver::class, fn(AgentFailedOver $event): bool => $event->invocationId === $response->invocationId);

    $invocationIds = collect(AiFlow::getAiEvents())
        ->filter(fn($event): bool => $event instanceof PromptingAgent)
        ->map(fn(PromptingAgent $event): string => $event->invocationId)
        ->unique();

    expect($invocationIds)->toHaveCount(1);
});

test('a nested sub agent sharing the parent provider instance does not overwrite the parent tool invocation id', function (): void {
    // Unfaked agents share one memoized provider, and therefore one generation loop, which is the case the tool invocation id has to survive...
    Configure::write('Ai.default', 'shared');
    agentEventsGroqProviders(['shared']);

    aiHttpFake([
        '*' => aiHttpSequence([
            agentEventsGroqToolCall('middle_manager', ['task' => 'Deep-dive on CakePHP caching'], 'call_001'),
            agentEventsGroqToolCall('research_agent', ['task' => 'Research CakePHP caching internals'], 'call_002'),
            fakeGroqResponse('Deep research result'),
            fakeGroqResponse('Research delegated.'),
            fakeGroqResponse('Delegated to middle manager.'),
        ]),
    ]);

    (new OrchestratorAgent())->prompt('Do a deep dive on CakePHP caching');

    $invoking = agentEventsOf(InvokingTool::class);
    $invoked = agentEventsOf(ToolInvoked::class);

    expect($invoking)->toHaveCount(2)->and($invoked)->toHaveCount(2);

    $delegatedTo = fn(string $agent): Closure => fn($event): bool => $event->tool instanceof AgentTool
        && $event->tool->agent() instanceof $agent;

    foreach ([MiddleManagerAgent::class, ResearchAgent::class] as $agent) {
        $start = collect($invoking)->filter($delegatedTo($agent))->first();
        $end = collect($invoked)->filter($delegatedTo($agent))->first();

        expect($end)->not->toBeNull()
            ->and($end->toolInvocationId)->toBe($start->toolInvocationId);
    }

    expect(collect($invoking)->map(fn($event): string => $event->toolInvocationId)->unique())->toHaveCount(2);
});

test('step events are dispatched for each step of a tool calling run', function (): void {
    MultiStepToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The number is 72019.',
    ]);

    $response = (new MultiStepToolAgent())->prompt('Generate a number');

    $this->assertAiEventCount(StartingStep::class, 2);
    $this->assertAiEventCount(StepCompleted::class, 2);

    $this->assertAiEventDispatched(StartingStep::class, fn(StartingStep $event): bool => $event->invocationId === $response->invocationId
        && $event->stepNumber === 0
        && $event->isFinalStep === false
        && $event->model !== '');

    $this->assertAiEventDispatched(StepCompleted::class, fn(StepCompleted $event): bool => $event->invocationId === $response->invocationId
        && $event->stepNumber === 0
        && $event->response->finishReason === FinishReason::ToolCalls
        && count($event->response->toolCalls) === 1);

    $this->assertAiEventDispatched(StepCompleted::class, fn(StepCompleted $event): bool => $event->stepNumber === 1
        && $event->response->finishReason === FinishReason::Stop
        && $event->response->toolCalls === []);
});

test('step completed carries the whole step response', function (): void {
    AssistantAgent::fake(['Hello!']);

    $agent = new AssistantAgent();

    $agent->prompt('Hi');

    $starting = agentEventsOf(StartingStep::class)[0];
    $completed = agentEventsOf(StepCompleted::class)[0];

    // The response travels whole so a consumer can record the text and usage of a step, not only that it finished...
    expect($completed->response)->toBeInstanceOf(StepResponse::class)
        ->and($completed->response->text)->toBe('Hello!')
        ->and($completed->response->meta->model)->not->toBeEmpty()
        ->and($completed->response->meta->provider)->not->toBeEmpty()
        ->and($completed->response->usage)->toBeInstanceOf(TextUsage::class)
        ->and($completed->agent)->toBe($agent)
        ->and($starting->agent)->toBe($agent)
        ->and($completed->time)->toBeFloat()->toBeGreaterThan(0.0);
});

test('starting step carries the messages and options the step is sent with', function (): void {
    MultiStepToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The number is 72019.',
    ]);

    (new MultiStepToolAgent())->prompt('Generate a number');

    [$first, $second] = agentEventsOf(StartingStep::class);

    expect($first->messages)->toHaveCount(1)
        ->and($first->messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($first->options)->toBeInstanceOf(TextGenerationOptions::class);

    // The second step is sent the assistant turn and the tool result the first step produced...
    expect($second->messages)->toHaveCount(3)
        ->and($second->messages[2])->toBeInstanceOf(ToolResultMessage::class);
});

test('conversation title generation does not report steps against the run that triggered it', function (): void {
    Ai::manager()->setConversationStore(new FakeConversationStore());

    RememberingAssistantAgent::fake(['Fake response', 'A Nice Title']);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent())->forUser($user)->prompt('Test prompt');

    // Title generation runs its own loop on the same provider, and must not be attributed to this run...
    $this->assertAiEventCount(StartingStep::class, 1);
    $this->assertAiEventCount(StepCompleted::class, 1);

    $this->assertAiEventDispatched(StartingStep::class, fn(StartingStep $event): bool => $event->invocationId === $response->invocationId
        && $event->stepNumber === 0);
});

test('step events are dispatched on the streaming path', function (): void {
    AssistantAgent::fake(['Hello!']);

    $response = (new AssistantAgent())->stream('Hi');

    foreach ($response as $event) {
        // Consume the stream.
    }

    $this->assertAiEventCount(StartingStep::class, 1);

    $this->assertAiEventDispatched(StepCompleted::class, fn(StepCompleted $event): bool => $event->invocationId === $response->invocationId
        && $event->stepNumber === 0
        && $event->response->finishReason === FinishReason::Stop);
});

test('a step that throws carries no stream error', function (): void {
    agentEventsGroqProviders(['only']);

    aiHttpFake([
        '*' => aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
    ]);

    expect(fn(): mixed => (new AssistantAgent())->prompt('Hi', provider: 'only'))
        ->toThrow(RateLimitedException::class);

    $failed = agentEventsOf(StepFailed::class)[0];

    expect($failed->exception)->toBeInstanceOf(RateLimitedException::class)
        ->and($failed->exception)->not->toBeInstanceOf(StreamErrorException::class)
        ->and($failed->time)->toBeFloat()->toBeGreaterThan(0.0);
});

test('a failed step identifies the provider and model that failed it', function (): void {
    agentEventsGroqProviders(['primary', 'backup']);

    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
            fakeGroqResponse('Hello from the backup.'),
        ]),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: ['primary', 'backup']);

    $starting = agentEventsOf(StartingStep::class)[0];
    $failed = agentEventsOf(StepFailed::class)[0];

    // Failover attempts share an invocation id and both restart at step zero, so the step events must name their own provider...
    expect($failed->agent)->toBeInstanceOf(AssistantAgent::class)
        ->and($failed->provider)->toBe($starting->provider)
        ->and($failed->model)->toBe($starting->model);
});

test('every step event names the provider and model the step ran against', function (): void {
    MultiStepToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'The number is 72019.',
    ]);

    (new MultiStepToolAgent())->prompt('Generate a number');

    $starting = agentEventsOf(StartingStep::class)[0];
    $completed = agentEventsOf(StepCompleted::class)[0];

    // A consumer builds a span from whichever event it sees, so the identity is read the same way on all of them...
    expect($completed->provider)->toBe($starting->provider)
        ->and($completed->model)->toBe($starting->model)
        ->and($completed->isFinalStep)->toBe($starting->isFinalStep)
        ->and($completed->agent)->toBe($starting->agent);
});

test('a throwing tool dispatches tool failed and still propagates the exception', function (): void {
    ToolUsingAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        ['number' => 5],
    ]);

    expect(fn(): mixed => (new ToolUsingAgent(fixed: true, toolThrowsException: true))->prompt('Generate'))
        ->toThrow(Exception::class, 'Forced to throw exception.');

    $this->assertAiEventDispatched(ToolFailed::class, fn(ToolFailed $event): bool => $event->tool instanceof FixedNumberGenerator
        && $event->exception->getMessage() === 'Forced to throw exception.');

    $this->assertAiEventNotDispatched(ToolInvoked::class);

    $this->assertAiEventDispatched(AgentFailed::class);

    $invoking = agentEventsOf(InvokingTool::class)[0];
    $failed = agentEventsOf(ToolFailed::class)[0];

    expect($failed->toolInvocationId)->toBe($invoking->toolInvocationId)
        ->and($failed->invocationId)->toBe($invoking->invocationId);
});

test('a tool that fails while resuming an approval reports the failure once and continues the run', function (): void {
    Configure::write('Ai.conversations.generate_title', false);
    Configure::write('Ai.conversations.connection', 'test');
    Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
    Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');

    Ai::manager()->setConversationStore(new DatabaseConversationStore());

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
                    'name' => 'ThrowingApprovableGenerator',
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
                'content' => [['type' => 'text', 'text' => 'The tool could not run.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingThrowingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue();

    $resumed = (new RememberingThrowingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    // The resume path turns the failure into a tool result, so the run survives but still reports the failure exactly once...
    $this->assertAiEventCount(ToolFailed::class, 1);
    $this->assertAiEventNotDispatched(ToolInvoked::class);
    $this->assertAiEventNotDispatched(AgentFailed::class);

    expect($resumed->text)->toBe('The tool could not run.');

    $failed = agentEventsOf(ToolFailed::class)[0];
    $invoking = agentEventsOf(InvokingTool::class)[0];

    expect($failed->exception->getMessage())->toBe('Forced to throw exception.')
        ->and($failed->toolInvocationId)->toBe($invoking->toolInvocationId);
});

test('a sub agent prompt is linked to the parent invocation and tool invocation', function (): void {
    DelegatingAgent::fake([
        new ToolCall('call_123', 'research_agent', ['task' => 'Research CakePHP']),
        'Research delegated.',
    ]);

    ResearchAgent::fake(['Research result']);

    $response = (new DelegatingAgent())->prompt('Delegate research about CakePHP');

    $invoking = agentEventsOf(InvokingTool::class)[0];

    $this->assertAiEventDispatched(PromptingAgent::class, fn(PromptingAgent $event): bool => $event->prompt->agent instanceof ResearchAgent
        && $event->prompt->parentInvocationId === $response->invocationId
        && $event->prompt->parentToolInvocationId === $invoking->toolInvocationId);

    $this->assertAiEventDispatched(PromptingAgent::class, fn(PromptingAgent $event): bool => $event->prompt->agent instanceof DelegatingAgent
        && $event->prompt->parentInvocationId === null
        && $event->prompt->parentToolInvocationId === null);
});

test('an agent prompted from a hand written tool is linked to the parent invocation', function (): void {
    DelegatingViaCustomToolAgent::fake([
        new ToolCall('call_1', 'AgentCallingTool', []),
        'Done.',
    ]);

    ResearchAgent::fake(['Research result']);

    $response = (new DelegatingViaCustomToolAgent())->prompt('Go');

    $invoking = agentEventsOf(InvokingTool::class)[0];

    $this->assertAiEventDispatched(PromptingAgent::class, fn(PromptingAgent $event): bool => $event->prompt->agent instanceof ResearchAgent
        && $event->prompt->parentInvocationId === $response->invocationId
        && $event->prompt->parentToolInvocationId === $invoking->toolInvocationId);
});

test('the parent invocation is restored once a tool call finishes', function (): void {
    DelegatingAgent::fake([
        new ToolCall('call_1', 'research_agent', ['task' => 'Research CakePHP']),
        'Delegated.',
    ]);

    ResearchAgent::fake(['Research result']);

    (new DelegatingAgent())->prompt('Delegate');

    ResearchAgent::fake(['Standalone result']);

    (new ResearchAgent())->prompt('Standalone');

    $prompts = collect(AiFlow::getAiEvents())
        ->filter(fn($event): bool => $event instanceof PromptingAgent)
        ->map(fn(PromptingAgent $event): AgentPrompt => $event->prompt)
        ->filter(fn($prompt): bool => $prompt->agent instanceof ResearchAgent)
        ->toList();

    $standalone = $prompts[count($prompts) - 1];

    expect($standalone->parentInvocationId)->toBeNull()
        ->and($standalone->parentToolInvocationId)->toBeNull();
});

test('the parent invocation never travels outside the current process', function (): void {
    ParentInvocation::within('inv_1', 'tool_1', function (): void {
        expect(ParentInvocation::current())->toBe(['inv_1', 'tool_1']);
    });

    expect(ParentInvocation::current())->toBe([null, null]);
});

test('a recoverable failover does not report the run as failed', function (): void {
    agentEventsGroqProviders(['primary', 'backup']);

    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
            fakeGroqResponse('Hello from the backup.'),
        ]),
    ]);

    $response = (new AssistantAgent())->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello from the backup.');

    // The recoverable attempt is reported through the per-step event, not the terminal one...
    $this->assertAiEventDispatched(StepFailed::class, fn(StepFailed $event): bool => $event->invocationId === $response->invocationId);
    $this->assertAiEventDispatched(AgentFailedOver::class);
    $this->assertAiEventDispatched(AgentPrompted::class);
    $this->assertAiEventNotDispatched(AgentFailed::class);
});

test('a recoverable failover does not report the streamed run as failed', function (): void {
    agentEventsGroqProviders(['primary', 'backup']);

    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
            agentEventsGroqStream('Hello from the backup.'),
        ]),
    ]);

    $response = (new AssistantAgent())->stream('Hi', provider: ['primary', 'backup']);

    foreach ($response as $event) {
        // Consume the stream.
    }

    $this->assertAiEventDispatched(StepFailed::class);
    $this->assertAiEventDispatched(AgentFailedOver::class);
    $this->assertAiEventDispatched(AgentStreamed::class);
    $this->assertAiEventNotDispatched(AgentFailed::class);
});

test('a run that exhausts every provider reports the failure once', function (): void {
    agentEventsGroqProviders(['primary', 'backup']);

    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
            aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
        ]),
    ]);

    expect(fn(): mixed => (new AssistantAgent())->prompt('Hi', provider: ['primary', 'backup']))
        ->toThrow(RateLimitedException::class);

    $this->assertAiEventCount(AgentFailedOver::class, 1);
    $this->assertAiEventCount(AgentFailed::class, 1);

    $failed = agentEventsOf(AgentFailed::class)[0];
    $failedOver = agentEventsOf(AgentFailedOver::class)[0];

    expect($failed->invocationId)->toBe($failedOver->invocationId)
        ->and($failed->prompt->prompt)->toBe('Hi');
});

test('a streamed run that exhausts every provider reports the failure once', function (): void {
    agentEventsGroqProviders(['primary', 'backup']);

    aiHttpFake([
        '*' => aiHttpSequence([
            aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
            aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
        ]),
    ]);

    $response = (new AssistantAgent())->stream('Hi', provider: ['primary', 'backup']);

    expect(function () use ($response): void {
        foreach ($response as $event) {
            // Consume the stream.
        }
    })->toThrow(RateLimitedException::class);

    $this->assertAiEventCount(AgentFailedOver::class, 1);
    $this->assertAiEventCount(AgentFailed::class, 1);

    $this->assertAiEventDispatched(AgentFailed::class, fn(AgentFailed $event): bool => $event->invocationId === $response->invocationId);
});

test('a single provider run with no failover still reports a failoverable failure', function (): void {
    agentEventsGroqProviders(['only']);

    aiHttpFake([
        '*' => aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
    ]);

    expect(fn(): mixed => (new AssistantAgent())->prompt('Hi', provider: 'only'))
        ->toThrow(RateLimitedException::class);

    $this->assertAiEventCount(AgentFailed::class, 1);
    $this->assertAiEventNotDispatched(AgentFailedOver::class);
});

test('a single provider stream with no failover still reports a failoverable failure', function (): void {
    agentEventsGroqProviders(['only']);

    aiHttpFake([
        '*' => aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
    ]);

    $response = (new AssistantAgent())->stream('Hi', provider: 'only');

    expect(function () use ($response): void {
        foreach ($response as $event) {
            // Consume the stream.
        }
    })->toThrow(RateLimitedException::class);

    $this->assertAiEventCount(AgentFailed::class, 1);
    $this->assertAiEventNotDispatched(AgentFailedOver::class);
});

test('a failing gateway dispatches step failed and agent failed instead of agent prompted', function (): void {
    AssistantAgent::fake([fn(): never => throw new RuntimeException('Provider exploded.')]);

    expect(fn(): mixed => (new AssistantAgent())->prompt('Hi'))
        ->toThrow(RuntimeException::class, 'Provider exploded.');

    $this->assertAiEventDispatched(StepFailed::class, fn(StepFailed $event): bool => $event->stepNumber === 0
        && $event->exception->getMessage() === 'Provider exploded.');

    $this->assertAiEventDispatched(AgentFailed::class, fn(AgentFailed $event): bool => $event->prompt->prompt === 'Hi'
        && $event->exception->getMessage() === 'Provider exploded.');

    $this->assertAiEventNotDispatched(AgentPrompted::class);

    $stepFailed = agentEventsOf(StepFailed::class)[0];
    $agentFailed = agentEventsOf(AgentFailed::class)[0];

    expect($stepFailed->invocationId)->toBe($agentFailed->invocationId);
});

test('a failing stream dispatches agent failed instead of agent streamed', function (): void {
    AssistantAgent::fake([fn(): never => throw new RuntimeException('Stream exploded.')]);

    $response = (new AssistantAgent())->stream('Hi');

    expect(function () use ($response): void {
        foreach ($response as $event) {
            // Consume the stream.
        }
    })->toThrow(RuntimeException::class, 'Stream exploded.');

    $this->assertAiEventDispatched(StepFailed::class);

    $this->assertAiEventDispatched(AgentFailed::class, fn(AgentFailed $event): bool => $event->invocationId === $response->invocationId
        && $event->exception->getMessage() === 'Stream exploded.');

    $this->assertAiEventNotDispatched(AgentStreamed::class);
});

test('a provider error inside the stream closes the step it opened', function (): void {
    agentEventsGroqProviders(['only']);

    aiHttpFake([
        '*' => agentEventsGroqStreamError('Upstream exploded.'),
    ]);

    $response = (new AssistantAgent())->stream('Hi', provider: 'only');

    expect(function () use ($response): void {
        foreach ($response as $event) {
            // Consume the stream.
        }
    })->toThrow('Upstream exploded.');

    // The provider reported the error in the stream rather than throwing, but the step still has to close...
    $this->assertAiEventCount(StartingStep::class, 1);
    $this->assertAiEventCount(StepFailed::class, 1);
    $this->assertAiEventNotDispatched(StepCompleted::class);
    $this->assertAiEventDispatched(AgentFailed::class);
    $this->assertAiEventNotDispatched(AgentStreamed::class);

    $starting = agentEventsOf(StartingStep::class)[0];
    $failed = agentEventsOf(StepFailed::class)[0];

    expect($failed->invocationId)->toBe($starting->invocationId)
        ->and($failed->stepNumber)->toBe($starting->stepNumber)
        ->and($failed->exception)->toBeInstanceOf(StreamErrorException::class)
        ->and($failed->exception->getMessage())->toBe('Upstream exploded.');

    // The provider's own classification rides on the exception, so a listener never loses the error's type or metadata...
    expect($failed->exception->error)->toBeInstanceOf(Error::class)
        ->and($failed->exception->error->message)->toBe('Upstream exploded.')
        ->and($failed->exception->error->type)->toBe('server_error');
});

test('a streamed failure after the stream started reports the run as failed', function (): void {
    agentEventsGroqProviders(['primary', 'backup']);

    aiHttpFake([
        '*' => agentEventsGroqStreamToolCall('RateLimitedNumberGenerator'),
    ]);

    $response = (new RateLimitedToolAgent())->stream('Generate', provider: ['primary', 'backup']);

    $seen = 0;

    // The stream had already reached the consumer, so the run can no longer be replayed against the backup...
    expect(function () use ($response, &$seen): void {
        foreach ($response as $event) {
            $seen++;
        }
    })->toThrow(RateLimitedException::class, 'Rate limited while running the tool.');

    expect($seen)->toBeGreaterThan(0);

    $this->assertAiEventCount(AgentFailed::class, 1);
    $this->assertAiEventNotDispatched(AgentFailedOver::class);
});
