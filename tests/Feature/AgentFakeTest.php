<?php
declare(strict_types=1);

use Crustum\Ai\Ai;
use Crustum\Ai\Approvals\Decision;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Http\Response;
use Crustum\Ai\Job\InvokeAgentJob;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Prompts\QueuedAgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\StructuredAgentResponse;
use Crustum\Ai\Responses\StructuredTextResponse;
use Crustum\Ai\Responses\TextResponse;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\Ai\Streaming\Event\ToolResult as ToolResultEvent;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ConversationalAgent;
use Crustum\Ai\Test\Fixtures\Agents\EmptySchemaStructuredAgent;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\StructuredAgent;
use PHPUnit\Framework\AssertionFailedError;

describe('prompt responses', function (): void {
    test('agents can be faked', function (): void {
        AssistantAgent::fake([
            'First response',
            fn(string $prompt): string => 'Second response (' . $prompt . ')',
            new TextResponse('Third response', new Usage(), new Meta()),
        ]);

        $response = (new AssistantAgent())->prompt('First prompt');
        expect($response->text)->toEqual('First response');

        $response = (new AssistantAgent())->prompt('Second prompt');
        expect($response->text)->toEqual('Second response (Second prompt)');

        $response = (new AssistantAgent())->prompt('Third prompt');
        expect($response->text)->toEqual('Third response');

        // Assertion tests...
        AssistantAgent::assertPrompted('First prompt');
        AssistantAgent::assertPromptedTimes(3);
        AssistantAgent::assertNotPrompted('Missing prompt');

        AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'First prompt');
    });

    test('can assert agent was never prompted', function (): void {
        AssistantAgent::fake();

        AssistantAgent::assertNeverPrompted();
    });

    test('agents can be faked with no predefined responses', function (): void {
        AssistantAgent::fake();

        $response = (new AssistantAgent())->prompt('First prompt');
        expect($response->text)->toEqual('Fake response for prompt: First prompt');

        $response = (new AssistantAgent())->prompt('Second prompt');
        expect($response->text)->toEqual('Fake response for prompt: Second prompt');
    });

    test('fake responses may expose a raw http response', function (): void {
        AssistantAgent::fake([
            (new TextResponse('Hello', new Usage(), new Meta()))->withRawResponse(new Response(200, ['X-RateLimit-Remaining-Requests' => '99'], '{}')),
        ]);

        $response = (new AssistantAgent())->prompt('Hi');

        expect($response->raw)->toBeInstanceOf(Response::class)
            ->and($response->raw->getHeaderLine('X-RateLimit-Remaining-Requests'))->toBe('99');
    });

    test('agents can be faked with a single closure that is invoked for every prompt', function (): void {
        AssistantAgent::fake(fn(string $prompt): string => 'Fake response for prompt: ' . $prompt);

        $response = (new AssistantAgent())->prompt('First prompt');
        expect($response->text)->toEqual('Fake response for prompt: First prompt');

        $response = (new AssistantAgent())->prompt('Second prompt');
        expect($response->text)->toEqual('Fake response for prompt: Second prompt');
    });

    test('agents can prevent stray prompts', function (): void {
        AssistantAgent::fake()->preventStrayPrompts();

        (new AssistantAgent())->prompt('First prompt');
    })->throws(RuntimeException::class);

    test('agents with structured output can be faked', function (): void {
        StructuredAgent::fake([
            ['symbol' => 'Au'],
            fn(string $prompt): array => ['symbol' => 'Ag (' . $prompt . ')'],
            new StructuredTextResponse(
                ['symbol' => 'Pb'],
                json_encode(['symbol' => 'Pb']),
                new Usage(),
                new Meta(),
            ),
        ]);

        $response = (new StructuredAgent())->prompt('Gold prompt');
        expect($response['symbol'])->toEqual('Au');

        $response = (new StructuredAgent())->prompt('Silver prompt');
        expect($response['symbol'])->toEqual('Ag (Silver prompt)');

        $response = (new StructuredAgent())->prompt('Lead prompt');
        expect($response['symbol'])->toEqual('Pb');
    });

    test('agents with structured output can be faked with no predefined responses', function (): void {
        StructuredAgent::fake();

        $response = (new StructuredAgent())->prompt('Gold prompt');

        expect($response['symbol'])->toBeString();
    });

    test('fake closures can throw exceptions', function (): void {
        AssistantAgent::fake(function (): void {
            throw new Exception('Something went wrong');
        });

        (new AssistantAgent())->prompt('Test prompt');
    })->throws(Exception::class);

    test('structured agents with empty schemas fall back to a text response', function (): void {
        EmptySchemaStructuredAgent::fake([
            new TextResponse('Hello', new Usage(), new Meta()),
        ]);

        $response = (new EmptySchemaStructuredAgent())->prompt('Anything');

        expect($response)->toBeInstanceOf(AgentResponse::class)
            ->and($response)->not->toBeInstanceOf(StructuredAgentResponse::class)
            ->and($response->text)->toEqual('Hello');
    });
});

describe('stream responses', function (): void {
    test('agent streams can be faked', function (): void {
        AssistantAgent::fake([
            'First response',
            fn(string $prompt): string => 'Second response (' . $prompt . ')',
            new TextResponse('Third response', new Usage(), new Meta()),
        ]);

        $response = (new AssistantAgent())->stream('First prompt');
        $response->each(fn(): true => true);
        expect($response->text)->toEqual('First response')
            ->and($response->events)->toHaveCount(6);

        $response = (new AssistantAgent())->stream('Second prompt');
        $response->each(fn(): true => true);
        expect($response->text)->toEqual('Second response (Second prompt)')
            ->and($response->events)->toHaveCount(8);

        $response = (new AssistantAgent())->stream('Third prompt');
        $response->each(fn(): true => true);
        expect($response->text)->toEqual('Third response')
            ->and($response->events)->toHaveCount(6);
    });

    test('faked stream events share the response invocation id', function (): void {
        AssistantAgent::fake(['Hello world']);

        $response = (new AssistantAgent())->stream('First prompt');

        $response->each(fn(): true => true);

        expect($response->events)
            ->each(fn($event) => $event->invocationId->toBe($response->invocationId));
    });

    test('faked empty response streams without text events', function (): void {
        AssistantAgent::fake(['']);

        $response = (new AssistantAgent())->stream('First prompt');
        $response->each(fn(): true => true);

        expect($response->text)->toEqual('')
            ->and($response->events)->toHaveCount(2)
            ->and(collect($response->events)->some(fn($event): bool => $event instanceof TextStart))->toBeFalse();
    });

    test('faked tool calls emit a tool call event while streaming', function (): void {
        MultiStepToolAgent::fake([
            new ToolCall('call_123', 'FixedNumberGenerator', []),
            'The number is 72019.',
        ]);

        $response = (new MultiStepToolAgent())->stream('Generate a number');
        $response->each(fn(): true => true);

        $events = collect($response->events);

        $toolCall = $events->filter(fn($event): bool => $event instanceof ToolCallEvent)->first();

        $searchIndex = function (iterable $items, callable $callback): int|false {
            $index = 0;
            foreach ($items as $item) {
                if ($callback($item)) {
                    return $index;
                }

                $index++;
            }

            return false;
        };

        expect($toolCall)->not->toBeNull()
            ->and($toolCall->toolCall->name)->toBe('FixedNumberGenerator')
            ->and($searchIndex($events, fn($event): bool => $event instanceof ToolCallEvent))
            ->toBeLessThan($searchIndex($events, fn($event): bool => $event instanceof ToolResultEvent));
    });
});

describe('queue responses', function (): void {
    test('queued agents can be faked', function (): void {
        AssistantAgent::fake();

        (new AssistantAgent())->queue('First prompt');

        AssistantAgent::assertQueued('First prompt');
        AssistantAgent::assertNotQueued('Second prompt');

        AssistantAgent::assertQueued(fn(QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'First prompt');

        AssistantAgent::assertNotQueued(fn(QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'Second prompt');
    });

    test('can assert agent was never queued', function (): void {
        AssistantAgent::fake();

        AssistantAgent::assertNeverQueued();
    });

    test('assert queued does not throw undefined key when agent was never queued', function (): void {
        AssistantAgent::fake();

        // Should fail the assertion gracefully, not throw an undefined array key error.
        try {
            AssistantAgent::assertQueued('Some prompt');
            test()->fail('Expected assertion to fail.');
        } catch (AssertionFailedError $assertionFailedError) {
            expect($assertionFailedError->getMessage())->toContain('An expected queued prompt was not received.');
        }
    });

    test('assert not queued does not throw undefined key when agent was never queued', function (): void {
        AssistantAgent::fake();

        // Should pass gracefully since the agent was never queued.
        AssistantAgent::assertNotQueued('Some prompt');
    });

    test('queued agents can be faked and then callback is executed', function (): void {
        aiSyncQueue();
        AssistantAgent::fake(['First response']);

        $GLOBALS['agentResponse'] = null;

        (new AssistantAgent())->queue('First prompt')->then(function ($response): void {
            $GLOBALS['agentResponse'] = $response;
        });

        AssistantAgent::assertQueued('First prompt');

        expect($GLOBALS['agentResponse'])->toBeInstanceOf(AgentResponse::class);
        expect($GLOBALS['agentResponse']->text)->toEqual('First response');
    });

    test('queued agents can be faked and then callback is not executed if queue is faked', function (): void {
        aiFakeQueue();
        AssistantAgent::fake(['First response']);

        $GLOBALS['agentResponse'] = null;

        (new AssistantAgent())->queue('First prompt')->then(function ($response): void {
            $GLOBALS['agentResponse'] = $response;
        });

        AssistantAgent::assertQueued('First prompt');

        expect($GLOBALS['agentResponse'])->toBeNull();

        $this->assertJobPushed(InvokeAgentJob::class);
    });
});

describe('provider enum support', function (): void {
    test('queued agents accept ai provider enum', function (): void {
        AssistantAgent::fake();

        (new AssistantAgent())->queue('Enum prompt', provider: Lab::OpenAI);

        AssistantAgent::assertQueued(fn(QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'Enum prompt'
            && $prompt->provider === Lab::OpenAI);
    });

    test('prompt accepts ai provider enum', function (): void {
        AssistantAgent::fake();

        (new AssistantAgent())->prompt('Enum prompt', provider: Lab::Anthropic);

        AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Enum prompt');
    });

    test('stream accepts ai provider enum', function (): void {
        AssistantAgent::fake();

        $response = (new AssistantAgent())->stream('Enum stream', provider: Lab::Gemini);
        $response->each(fn(): true => true);

        AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Enum stream');
    });
});

describe('timeout handling', function (): void {
    test('timeout can be passed to agent prompt', function (): void {
        AssistantAgent::fake();

        $timeout = 120;

        (new AssistantAgent())->prompt('Test prompt', timeout: $timeout);

        AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Test prompt'
            && $prompt->timeout === 120);
    });

    test('timeout defaults to sdk default when not provided', function (): void {
        AssistantAgent::fake();

        (new AssistantAgent())->prompt('Test prompt');

        AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Test prompt'
            && $prompt->timeout === 60);
    });

    test('timeout can be passed to agent stream', function (): void {
        AssistantAgent::fake();

        $timeout = 120;

        (new AssistantAgent())->stream('Test prompt', timeout: $timeout);

        AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Test prompt'
            && $prompt->timeout === 120);
    });

    test('timeout is preserved when revising agent prompt', function (): void {
        AssistantAgent::fake();

        $prompt = new AgentPrompt(
            new AssistantAgent(),
            'Original prompt',
            [],
            Ai::manager()->textProviderFor(new AssistantAgent(), 'groq'),
            'test-model',
            150,
        );

        $revised = $prompt->revise('Revised prompt');

        expect($revised->timeout)->toEqual(150)
            ->and($revised->prompt)->toEqual('Revised prompt');
    });

    test('agents can fake paused approval responses and assert resume prompts', function (): void {
        ConversationalAgent::fake([
            AgentResponse::fakeWithPendingApprovals([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
            ]),
            'Resumed',
        ]);

        $response = (new ConversationalAgent())->prompt('Delete config/app.php');

        expect($response->hasPendingApprovals())->toBeTrue()
            ->and($response->pendingApprovals)->toHaveCount(1);

        (new ConversationalAgent())->prompt(Decisions::from(['call-1' => true]));

        ConversationalAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->approvalDecisions?->get('call-1')?->isApproved() === true);
    });

    test('revising a resume prompt is a no-op since it carries no prompt text', function (): void {
        $prompt = new AgentPrompt(
            new AssistantAgent(),
            '',
            [],
            Ai::manager()->textProviderFor(new AssistantAgent(), 'groq'),
            'test-model',
            approvalDecisions: Decisions::from(['call-1' => Decision::approve()]),
        );

        $revised = $prompt->append('extra context');

        expect($revised)->toBe($prompt)
            ->and($revised->prompt)->toBe('')
            ->and($revised->approvalDecisions)->toBe($prompt->approvalDecisions);
    });
});
