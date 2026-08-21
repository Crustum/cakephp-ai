<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Audio;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

function fakeOpenAiAudioResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse('fake-audio-bytes');
}

test('audio request includes model, input, voice, response format, and speed', function (): void {
    aiHttpFake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-4o-mini-tts'
            && $body['input'] === 'Hello world'
            && $body['voice'] === 'alloy'
            && $body['response_format'] === 'mp3'
            && $body['speed'] == 1.0
            && $request->url() === 'https://api.openai.com/v1/audio/speech';
    });
});

test('audio request resolves default-female voice to alloy', function (): void {
    aiHttpFake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->female()->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['voice'] === 'alloy');
});

test('audio request resolves default-male voice to ash', function (): void {
    aiHttpFake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['voice'] === 'ash');
});

test('audio request passes custom voice id through unchanged', function (): void {
    aiHttpFake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->voice('nova')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['voice'] === 'nova');
});

test('audio request includes instructions when provided', function (): void {
    aiHttpFake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->instructions('Speak slowly')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['instructions'] === 'Speak slowly');
});

test('audio response is base64-encoded with audio/mpeg mime type', function (): void {
    aiHttpFake(['*' => fakeOpenAiAudioResponse()]);

    $response = Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    expect($response->audio)->toBe(base64_encode('fake-audio-bytes'))
        ->and($response->mimeType())->toBe('audio/mpeg')
        ->and($response->meta->provider)->toBe('openai')
        ->and($response->meta->model)->toBe('gpt-4o-mini-tts');
});

test('audio uses default model when none specified', function (): void {
    aiHttpFake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model'] === 'gpt-4o-mini-tts');
});

test('audio rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');
})->throws(RateLimitedException::class);

test('audio http error response throws request exception', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Bad request',
            ],
        ], 400),
    ]);

    Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');
})->throws(RequestException::class);

test('audio request sends bearer token', function (): void {
    aiHttpFake(['*' => fakeOpenAiAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openai', model: 'gpt-4o-mini-tts');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});
