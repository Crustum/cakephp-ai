<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Providers\GroqProvider;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Transcription;

beforeEach(function (): void {
    Configure::write('Ai.providers.groq', [
        'className' => GroqProvider::class,
        'driver' => 'groq',
        'key' => 'test-key',
    ]);
});

test('transcription posts audio to the transcriptions endpoint as multipart', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hello, world!'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://api.groq.com/openai/v1/audio/transcriptions'
        && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
});

test('transcription uses the default whisper model when none is configured', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'whisper-large-v3-turbo'));
});

test('transcription uses the configured default model when set', function (): void {
    Configure::write('Ai.providers.groq', [
        'className' => GroqProvider::class,
        'driver' => 'groq',
        'key' => 'test-key',
        'models' => ['transcription' => ['default' => 'custom-whisper-model']],
    ]);

    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'custom-whisper-model'));
});

test('transcription sends language when provided', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Bonjour'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('fr')
        ->generate(provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'name="language"'));
});

test('transcription omits language when not provided', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => ! str_contains($request->body(), 'name="language"'));
});

test('transcription diarize throws a logic exception without sending a request', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hi'])]);

    expect(fn(): TranscriptionResponse => Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'groq'))
        ->toThrow(LogicException::class, 'does not support diarized transcription');

    aiAssertHttpNothingSent();
});

test('transcription response text is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hello, world!'])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('groq')
        ->and($response->meta->model)->toBe('whisper-large-v3-turbo');
});

test('transcription segments are parsed when a verbose response format is requested', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'text' => 'Hello, world!',
        'segments' => [
            ['text' => 'Hello,', 'start' => 0.0, 'end' => 1.5],
            ['text' => 'world!', 'start' => 1.5, 'end' => 2.75],
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['response_format' => 'verbose_json'])
        ->generate(provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'verbose_json')
        && substr_count($request->body(), 'name="response_format"') === 1);

    expect($response->segments)->toHaveCount(2)
        ->and($response->segments->first()->text)->toBe('Hello,')
        ->and($response->segments->first()->startSeconds)->toBe(0.0)
        ->and($response->segments->last()->endSeconds)->toBe(2.75);
});

test('transcription sends the bearer token', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hi'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('transcription rate limit response throws rate limited exception', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['error' => ['message' => 'Rate limit exceeded']], 429)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');
})->throws(RateLimitedException::class);

test('transcription overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['error' => ['message' => 'Service overloaded']], 503)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');
})->throws(ProviderOverloadedException::class);

test('transcription http error response throws request exception', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['error' => ['message' => 'Invalid audio']], 400)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');
})->throws(RequestException::class);

test('transcription can be faked for the groq provider', function (): void {
    Transcription::fake(['Faked transcript']);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    expect($response->text)->toBe('Faked transcript');
});

test('transcription requests verbose json so the audio duration is returned', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hello, world!', 'duration' => 8.47])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'verbose_json'));

    expect($response->usage->audioSeconds)->toBe(8.47);
});

test('transcription response format can be overridden with a provider option', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hello, world!'])]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['response_format' => 'json'])
        ->generate(provider: 'groq');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => ! str_contains($request->body(), 'verbose_json'));
});

test('transcription leaves the audio duration null when not returned', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['text' => 'Hello, world!'])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'groq');

    expect($response->usage->audioSeconds)->toBeNull();
});
