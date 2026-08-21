<?php
declare(strict_types=1);

use Crustum\Ai\Ai;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\AgentStreamed;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingAssistantAgent;
use Crustum\Ai\Test\Fixtures\FakeConversationStore;

test('agent middleware is invoked', function (): void {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $response = (new AssistantAgent())
        ->withMiddleware([middleware()])
        ->prompt('Test prompt');

    expect($response->text)->toEqual('Fake response')
        ->and($_SERVER['__testing.middleware-prompt'])->toBeInstanceOf(AgentPrompt::class);

    unset($_SERVER['__testing.middleware-prompt']);
});

test('agent middleware is invoked when streaming', function (): void {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $response = (new AssistantAgent())
        ->withMiddleware([middleware()])
        ->stream('Test prompt');

    $response
        ->each(fn(): true => true)
        ->then(function (StreamedAgentResponse $response): void {
            $_SERVER['__testing.text'] = $response->text;
        });

    expect($_SERVER['__testing.text'])->toEqual('Fake response')
        ->and($_SERVER['__testing.middleware-prompt'])->toBeInstanceOf(AgentPrompt::class);

    unset($_SERVER['__testing.text']);
    unset($_SERVER['__testing.middleware-prompt']);
});

test('agent prompted event receives prompt when middleware short circuits', function (): void {
    AssistantAgent::fake([
        'Fake response',
    ]);

    (new AssistantAgent())
        ->withMiddleware([shortCircuitingMiddleware()])
        ->prompt('Test prompt');

    $this->assertAiEventDispatched(
        AgentPrompted::class,
        fn(AgentPrompted $event): bool => $event->prompt instanceof AgentPrompt
            && $event->prompt->prompt === 'Test prompt',
    );
    $this->assertNoToolsInvoked();
});

test('agent streamed event receives prompt when middleware short circuits a stream', function (): void {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $response = (new AssistantAgent())
        ->withMiddleware([streamingShortCircuitingMiddleware()])
        ->stream('Test prompt');

    foreach ($response as $event) {
    }

    $this->assertAiEventDispatched(
        AgentStreamed::class,
        fn(AgentStreamed $event): bool => $event->prompt instanceof AgentPrompt
            && $event->prompt->prompt === 'Test prompt',
    );
})->skip('Unsupported on Cake 4');

test('stream response conversation id is available after remembered conversations stream completes', function (): void {
    Ai::manager()->setConversationStore(new FakeConversationStore());

    RememberingAssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent())->forUser($user)->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->toBe('conversation-123')
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

function shortCircuitingMiddleware(): object
{
    return new class
    {
        public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
        {
            return new AgentResponse(
                'test-invocation-id',
                'Short-circuited response',
                new Usage(),
                new Meta(),
            );
        }
    };
}

function streamingShortCircuitingMiddleware(): object
{
    return new class
    {
        public function handle(AgentPrompt $prompt, Closure $next): StreamableAgentResponse
        {
            return new StreamableAgentResponse(
                'test-invocation-id',
                function () {
                    yield new TextDelta(
                        id: 'test-0',
                        messageId: 'test-invocation-id',
                        delta: 'Short-circuited response',
                        timestamp: 0,
                    );

                    yield new StreamEnd(
                        id: 'test-end',
                        reason: 'short_circuit',
                        usage: new Usage(),
                        timestamp: 0,
                    );
                },
                new Meta(),
            );
        }
    };
}

function middleware(): object
{
    return new class
    {
        public function handle(AgentPrompt $prompt, Closure $next)
        {
            $_SERVER['__testing.middleware-prompt'] = $prompt;

            return $next($prompt);
        }
    };
}
