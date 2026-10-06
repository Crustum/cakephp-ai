<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Audio;
use Crustum\Ai\Job\GenerateAudioJob;
use Crustum\Ai\Prompts\QueuedAudioPrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

function fakeAudioOptionsResponse(): array
{
    return ['*' => aiHttpResponse('fake-audio-bytes')];
}

test('provider options may not override the core audio request payload', function (): void {
    aiHttpFake(fakeAudioOptionsResponse());

    Audio::of('Hello world')
        ->withProviderOptions(['model' => 'hijacked', 'speed' => 0.8])
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-4o-mini-tts' && $body['speed'] === 0.8;
    });
});

test('closure provider options receive the resolved audio provider', function (): void {
    aiHttpFake(fakeAudioOptionsResponse());

    $seen = [];

    Audio::of('Hello world')
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return ['speed' => 0.8];
        })
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    expect($seen)->toBe(['openai']);

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['speed'] === 0.8);
});

test('flat provider options are recorded on the queued audio prompt fake', function (): void {
    Audio::fake();

    Audio::of('Hello world')
        ->withProviderOptions(['speed' => 0.8])
        ->queue(provider: 'openai');

    Audio::assertQueued(
        fn(QueuedAudioPrompt $prompt): bool => $prompt->providerOptions === ['speed' => 0.8],
    );
});

test('falsy provider options are not dropped from the audio request', function (): void {
    aiHttpFake(fakeAudioOptionsResponse());

    Audio::of('Hello world')
        ->withProviderOptions(['stream' => false])
        ->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return array_key_exists('stream', $body) && $body['stream'] === false;
    });
});

test('closure provider options survive queue serialization round-trip', function (): void {
    aiHttpFake(fakeAudioOptionsResponse());

    $pending = Audio::of('Hello world')
        ->withProviderOptions(fn(Provider $provider): array => ['speed' => 0.8]);

    $payload = GenerateAudioJob::payload($pending, 'openai', 'gpt-4o-mini-tts');
    $payload = json_decode(json_encode($payload), true);

    (new GenerateAudioJob())->run($payload);

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['speed'] === 0.8);
});
