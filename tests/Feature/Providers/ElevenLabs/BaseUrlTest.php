<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Audio;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Transcription;

function fakeElevenBaseUrlAudioResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse('fake-audio-bytes');
}

function fakeElevenBaseUrlTranscriptionResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse(['text' => 'Hello world']);
}

test('elevenlabs audio requests use the configured base url', function (): void {
    Configure::write('Ai.providers.eleven', [

        ...(array)Configure::read('Ai.providers.eleven'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]);

    aiHttpFake(['*' => fakeElevenBaseUrlAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => str_starts_with($r->url(), 'http://localhost:8080/v1/text-to-speech/'));
});

test('elevenlabs transcription requests use the configured base url', function (): void {
    Configure::write('Ai.providers.eleven', [

        ...(array)Configure::read('Ai.providers.eleven'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]);

    aiHttpFake(['*' => fakeElevenBaseUrlTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'eleven', model: 'scribe_v2');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => $r->url() === 'http://localhost:8080/v1/speech-to-text');
});

test('elevenlabs requests fall back to the default base url', function (): void {
    Configure::write('Ai.providers.eleven', array_diff_key(
        [...(array)Configure::read('Ai.providers.eleven'), 'key' => 'test-key'],
        ['url' => null],
    ));

    aiHttpFake(['*' => fakeElevenBaseUrlAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    aiAssertHttpSent(fn(AiHttpRequest $r): bool => str_starts_with($r->url(), 'https://api.elevenlabs.io/v1/text-to-speech/'));
});
