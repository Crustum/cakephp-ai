<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Job\GenerateTranscriptionJob;
use Crustum\Ai\Prompts\QueuedTranscriptionPrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Transcription;

beforeEach(function (): void {
    Ai::manager()->resetFakeState();

    Configure::write('Ai.providers.openai.key', 'test-key');
    // Configure::write('Ai.providers.mistral.key', 'test-key');

    aiHttpFake(fn(): AiHttpResponseDefinition => aiHttpResponse([
        'text' => 'Hello',
        'usage' => ['input_tokens' => 1, 'total_tokens' => 2],
    ]));
});

afterEach(function (): void {
    aiStopHttpFake();
});

test('flat provider options are sent on the transcription request', function (): void {
    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['prompt' => 'CakePHP Bake and DebugKit'])
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    $recorded = aiHttpRecorded();
    expect(json_encode($recorded[0][0]->data() ?? []))->toContain('CakePHP Bake and DebugKit');
});

test('closure resolver receives the resolved provider and applies per-provider options', function (): void {
    $seen = [];

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return $provider->driver() === 'openai'
                ? ['prompt' => 'OpenAI hint']
                : ['context_bias' => 'Mistral,hint'];
        })
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    $recorded = aiHttpRecorded();
    expect($seen)->toBe(['openai']);
    expect(json_encode($recorded[0][0]->data() ?? []))->toContain('OpenAI hint');
});

test('closure provider options are not recorded on the queued prompt fake', function (): void {
    Transcription::fake();

    Transcription::fromPath('/path/to/audio.mp3')
        ->withProviderOptions(fn(Provider $provider): array => ['prompt' => 'hint'])
        ->queue(provider: 'openai');

    Transcription::assertQueued(
        fn(QueuedTranscriptionPrompt $prompt): bool => $prompt->providerOptions === [],
    );
});

test('closure provider options survive queue serialization round-trip', function (): void {
    $pending = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(fn(Provider $provider): array => ['prompt' => 'Serialized hint']);

    $payload = GenerateTranscriptionJob::payload($pending, 'openai', 'gpt-4o-transcribe');
    $payload = json_decode(json_encode($payload), true);

    (new GenerateTranscriptionJob())->run($payload);

    $recorded = aiHttpRecorded();
    expect(json_encode($recorded[0][0]->data() ?? []))->toContain('Serialized hint');
});

test('closure resolver returning null is treated as no options', function (): void {
    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(fn(): null => null)
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    $recorded = aiHttpRecorded();
    expect(json_encode($recorded[0][0]->data() ?? []))->not->toContain('prompt');
});
