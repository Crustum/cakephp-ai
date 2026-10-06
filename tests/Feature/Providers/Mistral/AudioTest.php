<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Audio;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.mistral', [

        ...(array)Configure::read('Ai.providers.mistral'),
        'key' => 'test-key',
    ]);
});

test('audio request includes model, input, voice_id, and resolves default-female voice', function (): void {
    aiHttpFake(['*' => fakeMistralAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return str_contains($request->url(), 'audio/speech')
            && $body['model'] === 'voxtral-mini-tts-2603'
            && $body['input'] === 'Hello world'
            && $body['voice_id'] === 'gb_jane_neutral'
            && $body['response_format'] === 'mp3';
    });
});

test('audio request resolves default-male voice alias', function (): void {
    aiHttpFake(['*' => fakeMistralAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['voice_id'] === 'en_paul_neutral';
    });
});

test('audio request passes custom voice id through unchanged', function (): void {
    aiHttpFake(['*' => fakeMistralAudioResponse()]);

    Audio::of('Hello')->voice('my-custom-voice-id')->generate(provider: 'mistral', model: 'voxtral-mini-tts-2603');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['voice_id'] === 'my-custom-voice-id';
    });
});

function fakeMistralAudioResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'audio_data' => base64_encode('fake-audio-data'),
    ]);
}
