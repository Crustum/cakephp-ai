<?php
declare(strict_types=1);

use Crustum\Ai\Audio;
use Crustum\Ai\Event\AudioGenerated;
use Crustum\Ai\Event\GeneratingAudio;
use Crustum\Ai\Files;
use Crustum\Ai\Test\Support\Event\EventRecorder;
use Crustum\Ai\Test\Support\Skips\ApiKey;
use Crustum\Ai\Transcription;

test('audio can be generated', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $recorder = EventRecorder::start([GeneratingAudio::class, AudioGenerated::class]);

    $response = Audio::of('Hello there! How are you today?')->generate(provider: $provider);

    expect($response->meta->provider)->toEqual($provider)
        ->and($response->audio)->not->toBeEmpty()
        ->and($response->mimeType())->not->toBeNull();

    $recorder->assertMatches(GeneratingAudio::class, fn(GeneratingAudio $event): bool => $event->prompt->timeout === 30);
    $recorder->assertMatches(AudioGenerated::class, fn(AudioGenerated $event): bool => $event->prompt->timeout === 30);
})->with('tts-providers');

test('transcription can be generated from local path', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $transcription = Files\Audio::fromPath(__DIR__ . '/../Fixtures/audio.mp3')
        ->transcription()
        ->generate(provider: $provider);

    expect(str_contains(strtolower((string)$transcription), 'how are you today'))->toBeTrue();
})->with('transcription-providers');

test('audio can be transcribed after generation', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $audio = Audio::of('Hello there! How are you today?')->generate(provider: $provider);

    $transcription = Transcription::fromBase64($audio->audio, $audio->mimeType())
        ->generate(provider: $provider);

    expect(str_contains(strtolower((string)$transcription), 'how are you today'))->toBeTrue();
})->with('tts-providers');

test('transcription can be diarized', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $audio = Audio::of('Hello there! How are you today?')->generate(provider: $provider);

    $transcription = Transcription::fromBase64($audio->audio, $audio->mimeType())
        ->diarize()
        ->generate(provider: $provider);

    expect(str_contains(strtolower((string)$transcription), 'how are you today'))->toBeTrue()
        ->and($transcription->segments->count())->toBeGreaterThan(0);
})->with('diarization-providers');
