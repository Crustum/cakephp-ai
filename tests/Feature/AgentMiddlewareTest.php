<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Crustum\Ai\Ai;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Event\AgentFailed;
use Crustum\Ai\Event\InvokingTool;
use Crustum\Ai\Event\StartingStep;
use Crustum\Ai\Event\StepCompleted;
use Crustum\Ai\Event\StepFailed;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\TextGenerationLoop;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\PendingStep;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingAssistantAgent;
use Crustum\Ai\Test\Fixtures\CapturingStepGateway;
use Crustum\Ai\Test\Fixtures\FakeConversationStore;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\NamedTool;
use Crustum\Ai\Test\Support\Database\ConversationTable;

test('agent middleware wraps every generation step', function (): void {
    AssistantAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Fake response',
    ]);

    $seen = [];

    $response = (new AssistantAgent())
        ->withTools([new FixedNumberGenerator()])
        ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
            $lastStep = $step->steps === [] ? null : $step->steps[count($step->steps) - 1];
            $seen[] = [$step->number, count($step->messages), count($step->steps), $lastStep?->toolCalls[0]->id ?? null];

            return $next($step);
        }])
        ->prompt('Test prompt');

    expect($response->text)->toEqual('Fake response')
        ->and($seen)->toBe([[0, 1, 0, null], [1, 3, 1, 'call_1']]);
});

test('agent middleware wraps every generation step when streaming', function (): void {
    AssistantAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Fake response',
    ]);

    $seen = [];

    $response = (new AssistantAgent())
        ->withTools([new FixedNumberGenerator()])
        ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
            $seen[] = $step->number;

            return $next($step);
        }])
        ->stream('Test prompt');

    $text = null;

    $response
        ->each(fn(): true => true)
        ->then(function (StreamedAgentResponse $response) use (&$text): void {
            $text = $response->text;
        });

    expect($text)->toEqual('Fake response')
        ->and($seen)->toBe([0, 1]);
});

test('agent middleware sees the provider, accumulated usage and final step flag', function (): void {
    $gateway = new CapturingStepGateway();
    $seen = [];

    (new TextGenerationLoop($gateway))->generate(
        Ai::manager()->textProviderFor(new AssistantAgent(), 'openai'),
        'gpt-test',
        'Be helpful.',
        [new UserMessage('Hi')],
        [new FixedNumberGenerator()],
        options: new TextGenerationOptions(maxSteps: 2, agent: (new AssistantAgent())->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
            $seen[] = [$step->provider, $step->isFinalStep, $step->usage->inputTokens, $step->usage->outputTokens];

            return $next($step);
        }])),
    );

    expect($seen)->toBe([['openai', false, 0, 0], ['openai', true, 10, 5]]);
});

test('agent middleware then callback receives the step response before its tools run', function (): void {
    AssistantAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Fake response',
    ]);

    $seen = [];

    $listener = function (InvokingTool $event) use (&$seen): void {
        $seen[] = 'tool';
    };

    EventManager::instance()->on('Ai.invokingTool', $listener);

    try {
        (new AssistantAgent())
            ->withTools([new FixedNumberGenerator()])
            ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
                return $next($step)->then(function (StepResponse $response) use (&$seen): void {
                    $seen[] = $response->finishReason->value;
                });
            }])
            ->prompt('Test prompt');
    } finally {
        EventManager::instance()->off('Ai.invokingTool', $listener);
    }

    expect($seen)->toBe(['tool_calls', 'tool', 'stop']);
});

test('an outer middleware receives a step result when an inner middleware short-circuits', function (): void {
    AssistantAgent::fake(['Fake response']);

    $seen = [];

    $observer = function (PendingStep $step, Closure $next) use (&$seen) {
        return $next($step)->then(function (StepResponse $response) use (&$seen): void {
            $seen[] = $response->text;
        });
    };

    $response = (new AssistantAgent())
        ->withMiddleware([$observer, shortCircuitingMiddleware()])
        ->prompt('Test prompt');

    expect($response->text)->toBe('Short-circuited response')
        ->and($seen)->toBe(['Short-circuited response']);

    $streamed = (new AssistantAgent())
        ->withMiddleware([$observer, shortCircuitingMiddleware()])
        ->stream('Test prompt');

    iterator_to_array($streamed, false);

    expect($streamed->text)->toBe('Short-circuited response')
        ->and($seen)->toBe(['Short-circuited response', 'Short-circuited response']);
});

test('a failed step reports the model the middleware chose', function (): void {
    AssistantAgent::fake([fn() => throw new RuntimeException('Provider down.')]);

    expect(fn(): mixed => (new AssistantAgent())
        ->withMiddleware([fn(PendingStep $step, Closure $next) => $next($step->withModel('other-model'))])
        ->prompt('Test prompt'))->toThrow(RuntimeException::class, 'Provider down.');

    $this->assertAiEventDispatched(StepFailed::class, fn(StepFailed $event): bool => $event->model === 'other-model');
});

test('agent middleware then callback runs once a streamed step has drained', function (): void {
    AssistantAgent::fake(['Fake response']);

    $seen = [];

    $response = (new AssistantAgent())
        ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
            return $next($step)->then(function (StepResponse $response) use (&$seen): void {
                $seen[] = $response->text;
            });
        }])
        ->stream('Test prompt');

    expect($seen)->toBe([]);

    foreach ($response as $event) {
    }

    expect($seen)->toBe(['Fake response']);
});

test('agent middleware may short-circuit a step', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent())
        ->withMiddleware([shortCircuitingMiddleware()])
        ->prompt('Test prompt');

    expect($response->text)->toBe('Short-circuited response')
        ->and($response->steps)->toHaveCount(1);
});

test('agent middleware that reads the response of a streamed step still yields its events', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent())
        ->withMiddleware([function (PendingStep $step, Closure $next) {
            $result = $next($step);

            return $result->response()->text === '' ? new StepResponse('Fallback', [], FinishReason::Stop, new TextUsage(), new Meta()) : $result;
        }])
        ->stream('Test prompt');

    $events = iterator_to_array($response, false);

    expect(array_filter($events, fn(object $event): bool => $event instanceof TextDelta))->not->toBeEmpty()
        ->and($response->text)->toBe('Fake response');
});

test('agent middleware that iterates a streamed step itself still yields its events', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent())
        ->withMiddleware([function (PendingStep $step, Closure $next) {
            $result = $next($step);

            foreach ($result as $event) {
            }

            return $result;
        }])
        ->stream('Test prompt');

    iterator_to_array($response, false);

    expect($response->text)->toBe('Fake response');
});

test('agent middleware that replaces a streamed step response narrates the replacement', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent())
        ->withMiddleware([function (PendingStep $step, Closure $next) {
            $result = $next($step);

            return $result->response()->text === 'Fake response' ? new StepResponse('Replaced', [], FinishReason::Stop, new TextUsage(), new Meta()) : $result;
        }])
        ->stream('Test prompt');

    $deltas = array_values(array_filter(iterator_to_array($response, false), fn(object $event): bool => $event instanceof TextDelta));

    expect(array_map(fn(TextDelta $event): string => $event->delta, $deltas))->toBe(['Replaced']);
});

test("step provider options override the agent's own", function (): void {
    $agent = new class extends AssistantAgent implements HasProviderOptions {
        public function providerOptions(Lab|string $provider): array
        {
            return ['reasoning' => 'high', 'store' => false];
        }
    };

    $agent::fake(['Fake response']);

    $agent
        ->withMiddleware([fn(PendingStep $step, Closure $next) => $next($step->withProviderOptions(['reasoning' => 'low']))])
        ->prompt('Test prompt');

    $this->assertAiEventDispatched(StartingStep::class, fn(StartingStep $event): bool => $event->options->providerOptions('openai') === ['reasoning' => 'low', 'store' => false]);
});

test('agent middleware may short-circuit a streamed step', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent())
        ->withMiddleware([shortCircuitingMiddleware()])
        ->stream('Test prompt');

    $events = iterator_to_array($response, false);

    expect(array_map(fn(object $event): string => $event::class, $events))->toBe([StreamStart::class, TextStart::class, TextDelta::class, TextEnd::class, StreamEnd::class])
        ->and($response->text)->toBe('Short-circuited response');
});

test('agent middleware may change the model and options for a step', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent())
        ->withMiddleware([fn(PendingStep $step, Closure $next) => $next(
            $step->withModel('other-model')->withMaxTokens(1000)->withToolChoice('none')->withProviderOptions(['reasoning' => 'low']),
        )])
        ->prompt('Test prompt');

    expect($response->meta->model)->toBe('other-model');

    $this->assertAiEventDispatched(StartingStep::class, fn(StartingStep $event): bool => $event->model === 'other-model'
        && $event->options->maxTokens === 1000
        && $event->options->toolChoice->mode === ToolChoice::NONE
        && $event->options->providerOptions('openai') === ['reasoning' => 'low']);

    $this->assertAiEventDispatched(StepCompleted::class, fn(StepCompleted $event): bool => $event->model === 'other-model');
});

test('a streamed response reports the model the middleware chose', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent())
        ->withMiddleware([fn(PendingStep $step, Closure $next) => $next($step->withModel('other-model'))])
        ->stream('Test prompt');

    $response
        ->each(fn(): true => true)
        ->then(function (StreamedAgentResponse $response): void {
            expect($response->meta->model)->toBe('other-model');
        });

    $this->assertAiEventDispatched(StepCompleted::class, fn(StepCompleted $event): bool => $event->model === 'other-model');
});

test('agent middleware that replaces the history or the model drops the provider continuation token', function (): void {
    $gateway = new CapturingStepGateway();

    $run = function (Closure $middleware) use ($gateway): array {
        runThroughGateway($gateway, [$middleware]);

        return array_map(fn(array $call): ?string => $call['context']->continuationToken, $gateway->calls);
    };

    expect($run(fn(PendingStep $step, Closure $next) => $next($step)))->toBe([null, 'resp_1'])
        ->and($run(fn(PendingStep $step, Closure $next) => $next($step->withMessages(array_slice($step->messages, -1)))))->toBe([null, null])
        ->and($run(fn(PendingStep $step, Closure $next) => $next($step->isFirstStep() ? $step : $step->withModel('cheaper-model'))))->toBe([null, null])
        ->and($run(fn(PendingStep $step, Closure $next) => $next($step->isFirstStep() ? $step->withModel('cheaper-model') : $step)))->toBe([null, null])
        ->and($run(fn(PendingStep $step, Closure $next) => $next($step->withModel('cheaper-model'))))->toBe([null, 'resp_1'])
        ->and($run(fn(PendingStep $step, Closure $next) => $next($step->isFirstStep() ? $step : $step->withInstructions('Wrap up.'))))->toBe([null, null]);
});

test('agent middleware may narrow the tools and instructions for a step', function (): void {
    $gateway = new CapturingStepGateway();

    runThroughGateway($gateway, [
        fn(PendingStep $step, Closure $next) => $next($step->isFirstStep()
            ? $step->withoutTools('custom_named_tool', 'WebSearch')->withInstructions('Plan first.')
            : $step->onlyTools('custom_named_tool')),
    ], tools: [new FixedNumberGenerator(), new NamedTool(), new WebSearch()]);

    expect($gateway->calls[0]['tools'])->toHaveCount(1)
        ->and($gateway->calls[0]['tools'][0])->toBeInstanceOf(FixedNumberGenerator::class)
        ->and($gateway->calls[0]['instructions'])->toBe('Plan first.')
        ->and($gateway->calls[1]['tools'])->toHaveCount(1)
        ->and($gateway->calls[1]['tools'][0])->toBeInstanceOf(NamedTool::class)
        ->and($gateway->calls[1]['instructions'])->toBe('Be helpful.');
});

test('agent middleware must return the next step result or a step response', function (): void {
    AssistantAgent::fake(['Fake response']);

    (new AssistantAgent())
        ->withMiddleware([fn(PendingStep $step, Closure $next): string => 'nope'])
        ->prompt('Test prompt');
})->throws(LogicException::class, 'Agent middleware must return the next step result or a StepResponse.');

test('agent middleware that fails before the model is called fails the run without a step failure', function (): void {
    AssistantAgent::fake(['Fake response']);

    expect(fn(): mixed => (new AssistantAgent())
        ->withMiddleware([fn(PendingStep $step, Closure $next) => throw new RuntimeException('Blocked by middleware.')])
        ->prompt('Test prompt'))->toThrow(RuntimeException::class, 'Blocked by middleware.');

    $this->assertAiEventNotDispatched(StepFailed::class);
    $this->assertAiEventDispatched(AgentFailed::class, fn(AgentFailed $event): bool => $event->exception->getMessage() === 'Blocked by middleware.');
});

test('stream response conversation id is available after remembered conversations stream completes', function (): void {
    Ai::manager()->setConversationStore(new FakeConversationStore());

    RememberingAssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $agent = (new RememberingAssistantAgent())->forUser($user);

    $response = $agent->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->not->toBeNull()
        ->and($response->conversationId)->toBe($agent->currentConversation())
        ->and($response->conversationUser)->toBe($user);
});

test('stream response conversation id is available when continuing an existing conversation', function (): void {
    Ai::manager()->setConversationStore(new FakeConversationStore());

    RememberingAssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent())
        ->continue('existing-conversation-id', $user)
        ->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->toBe('existing-conversation-id')
        ->and($response->conversationUser)->toBe($user);
});

test('stream response conversation id syncs after late then callbacks', function (): void {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new AssistantAgent())->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    $response->then(function (StreamedAgentResponse $response) use ($user): void {
        $response->withinConversation('late-conversation-id', $user);
    });

    expect($response->conversationId)->toBe('late-conversation-id')
        ->and($response->conversationUser)->toBe($user);
});

test('stream response preserves manually assigned conversation id without a participant', function (): void {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $response = (new AssistantAgent())
        ->stream('Test prompt')
        ->withinConversation('manual-conversation-id');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->toBe('manual-conversation-id')
        ->and($response->conversationUser)->toBeNull();
});

test('an ownerless successful stream does not retain an unpersisted conversation id', function (): void {
    RememberingAssistantAgent::fake([
        'Fake response',
    ]);

    $agent = new RememberingAssistantAgent();
    $response = $agent->stream('Test prompt');

    foreach ($response as $_) {
    }

    expect($agent->currentConversation())->toBeNull()
        ->and($response->conversationId)->toBeNull()
        ->and($response->conversationUser)->toBeNull();
});

test('step middleware that compacts the history does not change what the conversation remembers', function (): void {
    Configure::write('Ai.conversations.generate_title', false);
    Configure::write('Ai.conversations.connection', 'test');
    Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
    Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');

    Ai::manager()->setConversationStore(new DatabaseConversationStore());

    RememberingAssistantAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Fake response',
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];
    $store = new DatabaseConversationStore();
    $conversationId = $store->storeConversation('user', $user->id, 'Compacted conversation');

    $sent = [];

    $response = (new RememberingAssistantAgent())
        ->withTools([new FixedNumberGenerator()])
        ->withMiddleware([
            fn(PendingStep $step, Closure $next) => $next($step->isFirstStep() ? $step : $step->withMessages(array_slice($step->messages, -1))),
            function (PendingStep $step, Closure $next) use (&$sent) {
                $sent[] = count($step->messages);

                return $next($step);
            },
        ])
        ->continue($conversationId, $user)
        ->prompt('Test prompt');

    $assistant = ConversationTable::first('agent_conversation_messages', ['conversation_id' => $conversationId, 'role' => 'assistant']);
    $remembered = $store->getLatestConversationMessages($conversationId, 10);

    expect($sent)->toBe([1, 1])
        ->and($response->text)->toBe('Fake response')
        ->and($assistant->content)->toBe('Fake response')
        ->and(json_decode((string)$assistant->steps, true)[0]['tool_calls'])->toHaveCount(1)
        ->and(json_decode((string)$assistant->steps, true)[0]['tool_calls'][0]['result'])->toBe('72019')
        ->and($remembered->map(fn($message): string => $message::class)->toList())->toBe([
            Message::class, AssistantMessage::class, ToolResultMessage::class, AssistantMessage::class,
        ]);
});

function runThroughGateway(CapturingStepGateway $gateway, array $middleware, array $tools = [new FixedNumberGenerator()]): void
{
    $gateway->calls = [];

    (new TextGenerationLoop($gateway))->generate(
        Ai::manager()->textProviderFor(new AssistantAgent(), 'openai'),
        'gpt-test',
        'Be helpful.',
        [new UserMessage('Hi')],
        $tools,
        options: TextGenerationOptions::forAgent((new AssistantAgent())->withMiddleware($middleware)),
    );
}

function shortCircuitingMiddleware(): object
{
    return new class
    {
        public function handle(PendingStep $step, Closure $next): StepResponse
        {
            return new StepResponse('Short-circuited response', [], FinishReason::Stop, new TextUsage(), new Meta());
        }
    };
}
