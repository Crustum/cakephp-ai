<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Audio;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.eleven', [

        ...(array)Configure::read('Ai.providers.eleven'),
        'key' => 'test-key',
    ]);
});

test('audio request includes model_id, text, and resolves default-female voice', function (): void {
    aiHttpFake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model_id'] === 'eleven_multilingual_v2'
            && $body['text'] === 'Hello world'
            && $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/XrExE9yKIg1WjnnlVkGX';
    });
});

test('audio request resolves default-male voice alias', function (): void {
    aiHttpFake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/onwK4e9ZLuTAKqWW03F9');
});

test('audio request passes custom voice id through unchanged', function (): void {
    aiHttpFake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->voice('my-custom-voice-id')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/my-custom-voice-id');
});

test('audio request sends xi-api-key header', function (): void {
    aiHttpFake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('xi-api-key', 'test-key'));
});

test('audio response is base64-encoded with audio/mpeg mime type', function (): void {
    aiHttpFake(['*' => aiHttpResponse('raw-audio-bytes')]);

    $response = Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    expect($response->audio)->toBe(base64_encode('raw-audio-bytes'))
        ->and($response->mimeType())->toBe('audio/mpeg')
        ->and($response->meta->provider)->toBe('eleven')
        ->and($response->meta->model)->toBe('eleven_multilingual_v2');
});

test('audio uses default model when none specified', function (): void {
    aiHttpFake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'eleven');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model_id'] === 'eleven_multilingual_v2');
});

test('audio throws when the API returns an error', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['detail' => 'unauthorized'], 401)]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
})->throws(RequestException::class);

test('audio rate limit response throws rate limited exception', function (): void {
    aiHttpFake(['api.elevenlabs.io/*' => aiHttpResponse(['detail' => 'rate limit exceeded'], 429)]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
})->throws(RateLimitedException::class);

test('audio overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake(['api.elevenlabs.io/*' => aiHttpResponse(['detail' => 'service unavailable'], 503)]);

    Audio::of('Hello')->generate(provider: 'eleven', model: 'eleven_multilingual_v2');
})->throws(ProviderOverloadedException::class);

function fakeElevenAudioResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse('fake-audio-bytes');
}
