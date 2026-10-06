<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Crustum\Ai\Ai;
use Crustum\Ai\Event\AgentFailedOver;
use Crustum\Ai\Event\AgentStreamed;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Providers\GroqProvider;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingAssistantAgent;
use Crustum\Ai\Test\Fixtures\ConversationStores\InMemoryConversationStore;
use Crustum\Ai\Test\Fixtures\Providers\FakeStreamingProvider;
use Crustum\Ai\Test\Support\Str;
use Crustum\Ai\TestSuite\AiFlow;

test('stream fails over to next provider when primary is rate limited', function (): void {
    aiConfigureGroqFailoverProviders();
    aiFakeGroqStreamFailoverHttp();

    $response = (new AssistantAgent())->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect(collect($events)->filter(fn($event): bool => $event instanceof TextDelta)->count())->toBeGreaterThan(0)
        ->and($response->text)->toBe('Hello');

    $this->assertProviderFailedOver('primary');
    $this->assertAiEventDispatched(AgentFailedOver::class);
    $this->assertAiEventDispatched(
        AgentStreamed::class,
        fn(AgentStreamed $event): bool => $event->invocationId === $response->invocationId,
    );
    $this->assertStreamEmitted(TextDelta::class);
    $this->assertStreamTextContains('Hello');
    $this->assertHttpSentToProvidersInOrder(['primary', 'backup']);
});

test('stream throws last exception when all providers fail', function (): void {
    aiConfigureGroqFailoverProviders();

    $this->fakeProviderHttp([
        '*' => $this->httpSequence([
            $this->httpResponse(['error' => ['message' => 'Rate limited']], 429),
            $this->httpResponse(['error' => ['message' => 'Rate limited']], 429),
        ]),
    ]);

    $response = (new AssistantAgent())->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    expect(function () use ($response): void {
        foreach ($response as $_) {
        }
    })->toThrow(RateLimitedException::class);
});

test('stream then callback is invoked after failover', function (): void {
    aiConfigureGroqFailoverProviders();
    aiFakeGroqStreamFailoverHttp();

    $response = (new AssistantAgent())->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    $thenResponse = null;

    $response->then(function (StreamedAgentResponse $r) use (&$thenResponse): void {
        $thenResponse = $r;
    });

    foreach ($response as $_) {
    }

    expect($thenResponse)->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($thenResponse->text)->toBe('Hello')
        ->and($thenResponse->meta->provider)->toBe('backup');
});

test('stream does not fail over when primary succeeds', function (): void {
    aiConfigureGroqFailoverProviders();

    $this->fakeProviderHttp([
        '*' => $this->httpResponse(
            fakeGroqStreamBodyForStreamFailover(),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $response = (new AssistantAgent())->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    foreach ($response as $_) {
    }

    expect($response->text)->toBe('Hello');

    $this->assertAiEventNotDispatched(AgentFailedOver::class);
    $this->assertStreamTextContains('Hello');
});

test('single provider stream does not dispatch failover event when rate limited', function (): void {
    Configure::write('Ai.providers.primary', [
        'className' => GroqProvider::class,
        'driver' => 'groq',
        'key' => 'test-key',
        'name' => 'primary',
    ]);

    $this->fakeProviderHttp([
        '*' => $this->httpResponse(['error' => ['message' => 'Rate limited']], 429),
    ]);

    $response = (new AssistantAgent())->stream(
        'Hello',
        provider: 'primary',
    );

    expect(function () use ($response): void {
        foreach ($response as $_) {
        }
    })->toThrow(RateLimitedException::class);

    $this->assertAiEventNotDispatched(AgentFailedOver::class);
});

test('stream does not fail over when primary emits event then throws', function (): void {
    Configure::write('Ai.providers.primary', ['driver' => 'mid_stream_failing', 'name' => 'primary']);
    Configure::write('Ai.providers.backup', ['driver' => 'working_backup', 'name' => 'backup']);

    Ai::getManager()->registerProviderInstances([
        'primary' => new FakeStreamingProvider(
            ['name' => 'primary', 'driver' => 'mid_stream_failing'],
            EventManager::instance(),
            fn($provider, $prompt): StreamableAgentResponse => new StreamableAgentResponse(
                Str::uuid7(),
                function () {
                    yield (new TextDelta('m1', 'm1', 'partial', 0))->withInvocationId('inner-fail');

                    throw RateLimitedException::forProvider('mid_stream_failing');
                },
                new Meta($provider->name(), $prompt->model),
            ),
        ),
        'backup' => new FakeStreamingProvider(
            ['name' => 'backup', 'driver' => 'working_backup'],
            EventManager::instance(),
            fn($provider, $prompt): StreamableAgentResponse => new StreamableAgentResponse(
                Str::uuid7(),
                function () {
                    yield (new TextDelta('m2', 'm2', 'World', 0))->withInvocationId('inner-success');
                },
                new Meta($provider->name(), $prompt->model),
            ),
        ),
    ]);

    $response = (new AssistantAgent())->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    expect(function () use ($response): void {
        foreach ($response as $_) {
        }
    })->toThrow(RateLimitedException::class);

    $this->assertAiEventNotDispatched(AgentFailedOver::class);
});

test('stream does not fail over when primary throws non failoverable exception', function (): void {
    $backupStreamed = false;

    Configure::write('Ai.providers.primary', ['driver' => 'malformed_stream', 'name' => 'primary']);
    Configure::write('Ai.providers.backup', ['driver' => 'backup_after_malformed_stream', 'name' => 'backup']);

    Ai::getManager()->registerProviderInstances([
        'primary' => new FakeStreamingProvider(
            ['name' => 'primary', 'driver' => 'malformed_stream'],
            EventManager::instance(),
            fn($provider, $prompt): StreamableAgentResponse => new StreamableAgentResponse(
                Str::uuid7(),
                function (): void {
                    throw new InvalidArgumentException('Malformed stream response.');
                },
                new Meta($provider->name(), $prompt->model),
            ),
        ),
        'backup' => new FakeStreamingProvider(
            ['name' => 'backup', 'driver' => 'backup_after_malformed_stream'],
            EventManager::instance(),
            function ($provider, $prompt) use (&$backupStreamed): StreamableAgentResponse {
                $backupStreamed = true;

                return new StreamableAgentResponse(
                    Str::uuid7(),
                    function () {
                        yield (new TextDelta('m2', 'm2', 'World', 0))->withInvocationId('inner-success');
                    },
                    new Meta($provider->name(), $prompt->model),
                );
            },
        ),
    ]);

    $response = (new AssistantAgent())->stream(
        'Hello',
        provider: ['primary', 'backup'],
    );

    expect(function () use ($response): void {
        foreach ($response as $_) {
        }
    })->toThrow(InvalidArgumentException::class, 'Malformed stream response.');

    expect($backupStreamed)->toBeFalse();
    $this->assertAiEventNotDispatched(AgentFailedOver::class);
});

test('stream conversation state survives failover', function (): void {
    $store = new InMemoryConversationStore();
    Ai::manager()->setConversationStore($store);

    aiConfigureGroqFailoverProviders();
    aiFakeGroqStreamFailoverHttp();

    $user = (object)['id' => 'user-1'];
    $existingConversationId = 'existing-conv-1';

    $response = (new RememberingAssistantAgent())
        ->continue($existingConversationId, $user)
        ->stream('Hello', provider: ['primary', 'backup']);

    $thenResponse = null;

    $response->then(function (StreamedAgentResponse $r) use (&$thenResponse): void {
        $thenResponse = $r;
    });

    foreach ($response as $_) {
    }

    expect($thenResponse->conversationId)->toBe($existingConversationId)
        ->and($thenResponse->conversationUser)->toBe($user)
        ->and($response->userMessageId)->not->toBeNull()
        ->and($response->assistantMessageId)->not->toBeNull()
        ->and(collection($store->messages)->filter(fn(array $message): bool => $message['id'] === $response->userMessageId)->first()['role'])->toBe('user')
        ->and(collection($store->messages)->filter(fn(array $message): bool => $message['id'] === $response->assistantMessageId)->first()['role'])->toBe('assistant');
});

test('first turn stream failover persists its reserved conversation', function (): void {
    $store = new InMemoryConversationStore();
    Ai::manager()->setConversationStore($store);

    aiConfigureGroqFailoverProviders();
    aiFakeGroqStreamFailoverHttp();

    $agent = (new RememberingAssistantAgent())->forUser((object)['id' => 'user-1']);
    $response = $agent->stream('Hello', provider: ['primary', 'backup']);

    foreach ($response as $_) {
    }

    expect($store->conversations)->toHaveCount(1)
        ->and($store->conversations)->toHaveKey($agent->currentConversation())
        ->and($response->conversationId)->toBe($agent->currentConversation())
        ->and(collection($store->messages)->extract('conversation_id')->filter()->unique()->toList())
        ->toBe([$agent->currentConversation()]);
});

function aiConfigureGroqFailoverProviders(): void
{
    Configure::write('Ai.providers.primary', [
        'className' => GroqProvider::class,
        'driver' => 'groq',
        'key' => 'test-key',
        'name' => 'primary',
    ]);
    Configure::write('Ai.providers.backup', [
        'className' => GroqProvider::class,
        'driver' => 'groq',
        'key' => 'test-key',
        'name' => 'backup',
    ]);
}

function aiFakeGroqStreamFailoverHttp(): void
{
    AiFlow::fakeProviderHttp([
        '*' => AiFlow::httpSequence([
            AiFlow::httpResponse(['error' => ['message' => 'Rate limited']], 429),
            AiFlow::httpResponse(
                fakeGroqStreamBodyForStreamFailover(),
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);
}

function fakeGroqStreamBodyForStreamFailover(): string
{
    $chunks = [
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{"role":"assistant","content":""},"finish_reason":null}]}',
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}',
        '{"id":"chatcmpl-1","object":"chat.completion.chunk","created":1,"model":"test","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":5,"completion_tokens":1,"total_tokens":6}}',
    ];

    $body = '';

    foreach ($chunks as $chunk) {
        $body .= "data: {$chunk}\n\n";
    }

    return $body . "data: [DONE]\n\n";
}
