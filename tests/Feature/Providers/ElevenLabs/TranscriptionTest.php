<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Transcription;

beforeEach(function (): void {
    Configure::write('Ai.providers.eleven', [

        ...(array)Configure::read('Ai.providers.eleven'),
        'key' => 'test-key',
    ]);
});

test('transcription request posts to speech-to-text with model, language code, and diarize flag', function (): void {
    aiHttpFake(['*' => fakeElevenTranscriptionResponse()]);

    Transcription::of(base64_encode('fake-audio'))
        ->language('en')
        ->generate(provider: 'eleven', model: 'scribe_v2');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/speech-to-text'
        && $request->isMultipart()
        && multipartField($request, 'model_id') === 'scribe_v2'
        && multipartField($request, 'language_code') === 'en'
        && multipartField($request, 'diarize') === 'false');
});

test('transcription request sends diarize=true when enabled', function (): void {
    aiHttpFake(['*' => fakeElevenTranscriptionResponse(diarized: true)]);

    Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'eleven', model: 'scribe_v2');

    aiAssertHttpSent(
        fn(AiHttpRequest $request): bool => multipartField($request, 'diarize') === 'true',
    );
});

test('transcription request sends xi-api-key header', function (): void {
    aiHttpFake(['*' => fakeElevenTranscriptionResponse()]);

    Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('xi-api-key', 'test-key'));
});

test('transcription response returns plain text with no segments when diarize is off', function (): void {
    aiHttpFake(['*' => fakeElevenTranscriptionResponse()]);

    $response = Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');

    expect($response->text)->toBe('Hello world')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('eleven')
        ->and($response->meta->model)->toBe('scribe_v2');
});

test('transcription response builds segments from words when diarize is on', function (): void {
    aiHttpFake(['*' => fakeElevenTranscriptionResponse(diarized: true)]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'eleven', model: 'scribe_v2');

    expect($response->segments)->toHaveCount(2)
        ->and($response->segments->toList()[0]->text)->toBe('Hello')
        ->and($response->segments->toList()[0]->speaker)->toBe('speaker_0')
        ->and($response->segments->toList()[0]->startSeconds)->toBe(0.0)
        ->and($response->segments->toList()[0]->endSeconds)->toBe(0.5)
        ->and($response->segments->toList()[1]->text)->toBe('world')
        ->and($response->segments->toList()[1]->speaker)->toBe('speaker_1');
});

test('transcription filters out non-word segments (e.g. spacing)', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'text' => 'Hello world',
        'words' => [
            ['type' => 'word', 'text' => 'Hello', 'speaker_id' => 'speaker_0', 'start' => 0.0, 'end' => 0.5],
            ['type' => 'spacing', 'text' => ' ', 'start' => 0.5, 'end' => 0.6],
            ['type' => 'word', 'text' => 'world', 'speaker_id' => 'speaker_0', 'start' => 0.6, 'end' => 1.0],
        ],
    ])]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'eleven', model: 'scribe_v2');

    expect($response->segments)->toHaveCount(2)
        ->and($response->segments->map(fn($s) => $s->text)->toList())->toBe(['Hello', 'world']);
});

test('transcription uses default model when none specified', function (): void {
    aiHttpFake(['*' => fakeElevenTranscriptionResponse()]);

    Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven');

    aiAssertHttpSent(
        fn(AiHttpRequest $request): bool => multipartField($request, 'model_id') === 'scribe_v2',
    );
});

test('transcription sends enable_logging as a query parameter instead of in the body', function (): void {
    aiHttpFake(['*' => fakeElevenTranscriptionResponse()]);

    Transcription::of(base64_encode('fake-audio'))
        ->withProviderOptions(['enable_logging' => false, 'tag_audio_events' => 'true'])
        ->generate(provider: 'eleven', model: 'scribe_v2');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/speech-to-text?enable_logging=false'
        && multipartField($request, 'enable_logging') === null
        && multipartField($request, 'tag_audio_events') === 'true');
});

test('transcription throws when the API returns an error', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['detail' => 'unauthorized'], 401)]);

    Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');
})->throws(RequestException::class);

function fakeElevenTranscriptionResponse(bool $diarized = false): AiHttpResponseDefinition
{
    $body = ['text' => 'Hello world'];

    if ($diarized) {
        $body['words'] = [
            ['type' => 'word', 'text' => 'Hello', 'speaker_id' => 'speaker_0', 'start' => 0.0, 'end' => 0.5],
            ['type' => 'word', 'text' => 'world', 'speaker_id' => 'speaker_1', 'start' => 0.6, 'end' => 1.0],
        ];
    }

    return aiHttpResponse($body);
}

test('transcription reports the transcribed audio duration', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hello, world!', 'audio_duration_secs' => 41.2])]);

    $response = Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');

    expect($response->usage->audioSeconds)->toBe(41.2);
});

test('transcription leaves the audio duration null when not returned', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hello, world!'])]);

    $response = Transcription::of(base64_encode('fake-audio'))->generate(provider: 'eleven', model: 'scribe_v2');

    expect($response->usage->audioSeconds)->toBeNull();
});
