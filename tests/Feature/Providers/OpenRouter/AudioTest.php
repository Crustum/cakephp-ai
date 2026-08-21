<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Audio;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

function fakeOpenRouterAudioResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse('fake-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']);
}

test('audio request includes model, input, voice, response format, and speed', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello world')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'openai/gpt-4o-mini-tts-2025-12-15'
            && $body['input'] === 'Hello world'
            && $body['voice'] === 'alloy'
            && $body['response_format'] === 'mp3'
            && $body['speed'] == 1.0
            && $request->url() === 'https://openrouter.ai/api/v1/audio/speech';
    });
});

test('audio request resolves default-female voice to alloy', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->female()->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['voice'] === 'alloy');
});

test('audio request resolves default-male voice to ash', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['voice'] === 'ash');
});

test('audio request passes custom voice id through unchanged', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->voice('shimmer')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['voice'] === 'shimmer');
});

test('audio request includes instructions when provided', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->instructions('Speak slowly')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['instructions'] === 'Speak slowly');
});

test('audio request omits instructions when not provided', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => ! array_key_exists('instructions', json_decode($request->body(), true)));
});

test('audio uses default model when none specified', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model'] === 'google/gemini-3.1-flash-tts-preview');
});

test('audio request to gemini tts model uses pcm response format and pcm mime', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    $response = Audio::of('Hello')->generate(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['response_format'] === 'pcm');

    expect($response->mimeType())->toBe('audio/pcm');
});

test('audio request to gemini tts model resolves default-female voice to Kore', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->female()->generate(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['voice'] === 'Kore');
});

test('audio request to gemini tts model resolves default-male voice to Puck', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->male()->generate(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['voice'] === 'Puck');
});

test('audio response is base64-encoded with audio/mpeg mime type', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    $response = Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    expect($response->audio)->toBe(base64_encode('fake-audio-bytes'))
        ->and($response->mimeType())->toBe('audio/mpeg')
        ->and($response->meta->provider)->toBe('openrouter')
        ->and($response->meta->model)->toBe('openai/gpt-4o-mini-tts-2025-12-15');
});

test('audio request sends bearer token', function (): void {
    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('audio request sends openrouter attribution headers when configured', function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
    Configure::write('Ai.providers.openrouter.http_referer', 'https://example.test');
    Configure::write('Ai.providers.openrouter.x_title', 'Example App');

    aiHttpFake(['*' => fakeOpenRouterAudioResponse()]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('HTTP-Referer', 'https://example.test')
        && $request->hasHeader('X-OpenRouter-Title', 'Example App'));
});

test('audio rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'message' => 'Rate limit exceeded',
                'code' => 429,
            ],
        ], 429),
    ]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');
})->throws(RateLimitedException::class);

test('audio overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'message' => 'Service overloaded',
                'code' => 503,
            ],
        ], 503),
    ]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');
})->throws(ProviderOverloadedException::class);

test('audio http error response throws request exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'message' => 'Bad request',
                'code' => 400,
            ],
        ], 400),
    ]);

    Audio::of('Hello')->generate(provider: 'openrouter', model: 'openai/gpt-4o-mini-tts-2025-12-15');
})->throws(RequestException::class);
