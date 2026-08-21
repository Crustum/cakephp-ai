<?php
declare(strict_types=1);

use Crustum\Ai\Audio;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\AgentStreamed;
use Crustum\Ai\Event\EmbeddingsGenerated;
use Crustum\Ai\Event\GeneratingEmbeddings;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Support\Event\EventRecorder;
use Crustum\Ai\Test\Support\IntegrationPrompts;
use Crustum\Ai\Test\Support\Skips\ApiKey;
use Crustum\Ai\Transcription;

beforeEach(fn() => ApiKey::required('GROQ_API_KEY', 'OPENAI_API_KEY'));

test('agent prompt accepts ai provider enum', function (): void {
    $recorder = EventRecorder::start([AgentPrompted::class]);

    $response = (new AssistantAgent())->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: Lab::Groq,
        model: 'openai/gpt-oss-20b',
    );

    expect(IntegrationPrompts::matches('knowledge', $response->text))->toBeTrue()
        ->and($response->meta->provider)->toEqual('groq');

    $recorder->assertDispatched(AgentPrompted::class);
});

test('agent stream accepts ai provider enum', function (): void {
    $recorder = EventRecorder::start([AgentStreamed::class]);

    $response = (new AssistantAgent())->stream(
        IntegrationPrompts::question('knowledge'),
        provider: Lab::Groq,
        model: 'openai/gpt-oss-20b',
    );

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect(collect($events)->filter(fn($event): bool => $event instanceof TextDelta)->count())->toBeGreaterThan(0)
        ->and(IntegrationPrompts::matches('knowledge', (string)$response->text))->toBeTrue();

    $recorder->assertDispatched(AgentStreamed::class);
});

test('agent queue accepts ai provider enum', function (): void {
    $response = (new AssistantAgent())->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: Lab::Groq,
        model: 'openai/gpt-oss-20b',
    );

    expect(IntegrationPrompts::matches('knowledge', $response->text))->toBeTrue();
});

test('agent prompt accepts array of ai provider enum values for failover', function (): void {
    $response = (new AssistantAgent())->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: [Lab::Groq],
        model: 'openai/gpt-oss-20b',
    );

    expect(IntegrationPrompts::matches('knowledge', $response->text))->toBeTrue()
        ->and($response->meta->provider)->toEqual('groq');
});

test('embeddings generate accepts ai provider enum', function (): void {
    $recorder = EventRecorder::start([GeneratingEmbeddings::class, EmbeddingsGenerated::class]);

    $response = Embeddings::for(['I love to watch Star Trek.'])
        ->generate(provider: Lab::OpenAI);

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings[0])->toHaveCount(1536)
        ->and($response->meta->provider)->toEqual('openai');

    $recorder->assertDispatched(GeneratingEmbeddings::class);
    $recorder->assertDispatched(EmbeddingsGenerated::class);
});

test('audio generate accepts ai provider enum', function (): void {
    $response = Audio::of('Hello there! How are you today?')
        ->generate(provider: Lab::OpenAI);

    expect($response->meta->provider)->toEqual('openai');
});

test('transcription generate accepts ai provider enum', function (): void {
    $audio = Audio::of('Hello there! How are you today?')->generate();

    $transcription = Transcription::of($audio->audio)
        ->generate(provider: Lab::OpenAI);

    expect(str_contains(strtolower((string)$transcription), 'how are you today'))->toBeTrue();
});
