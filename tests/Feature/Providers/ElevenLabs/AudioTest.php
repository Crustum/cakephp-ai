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
    aiHttpFake(['*' => aiHttpResponse('raw-audio-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

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

test('audio sends query string provider options as query parameters instead of in the body', function (bool $enableLogging, string $expected): void {
    aiHttpFake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')
        ->withProviderOptions([
            'output_format' => 'wav_44100',
            'enable_logging' => $enableLogging,
            'optimize_streaming_latency' => 0,
        ])
        ->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    aiAssertHttpSent(function (AiHttpRequest $request) use ($expected): bool {
        parse_str((string)parse_url($request->url(), PHP_URL_QUERY), $query);

        $body = json_decode($request->body(), true) ?? [];

        return str_starts_with($request->url(), 'https://api.elevenlabs.io/v1/text-to-speech/XrExE9yKIg1WjnnlVkGX?')
            && $query === ['output_format' => 'wav_44100', 'enable_logging' => $expected, 'optimize_streaming_latency' => '0']
            && !array_intersect_key($body, ['output_format' => true, 'enable_logging' => true, 'optimize_streaming_latency' => true]);
    });
})->with([
    [false, 'false'],
    [true, 'true'],
]);

test('audio keeps body provider options in the request body', function (): void {
    aiHttpFake(['*' => fakeElevenAudioResponse()]);

    Audio::of('Hello')
        ->withProviderOptions([
            'output_format' => 'mp3_44100_192',
            'seed' => 42,
            'voice_settings' => ['stability' => 0.5],
            'model_id' => 'hijacked',
        ])
        ->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['seed'] === 42
            && $body['voice_settings'] === ['stability' => 0.5]
            && $body['model_id'] === 'eleven_multilingual_v2'
            && $body['text'] === 'Hello';
    });
});

test('audio response mime type follows the returned content type', function (): void {
    aiHttpFake(['*' => aiHttpResponse('fake-audio-bytes', 200, ['Content-Type' => 'audio/wav'])]);

    $response = Audio::of('Hello')
        ->withProviderOptions(['output_format' => 'wav_44100'])
        ->generate(provider: 'eleven', model: 'eleven_multilingual_v2');

    expect($response->mimeType())->toBe('audio/wav');
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
