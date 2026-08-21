<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Transcription;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

function fakeOpenRouterTranscriptionResponse(string $text = 'Hello, world!'): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'text' => $text,
        'usage' => [
            'seconds' => 1.5,
            'total_tokens' => 30,
            'input_tokens' => 20,
            'output_tokens' => 10,
            'cost' => 0.000100,
        ],
    ]);
}

test('transcription request posts to correct endpoint as json', function (): void {
    aiHttpFake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://openrouter.ai/api/v1/audio/transcriptions'
        && str_contains($request->header('Content-Type')[0] ?? '', 'application/json'));
});

test('transcription request sends audio as base64 with format from mime type', function (): void {
    aiHttpFake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['input_audio']['data'] === base64_encode('fake-audio')
            && $body['input_audio']['format'] === 'mp3';
    });
});

test('transcription diarize throws logic exception without sending request', function (): void {
    aiHttpFake(['*' => fakeOpenRouterTranscriptionResponse()]);

    expect(fn(): TranscriptionResponse => Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'openrouter'))
        ->toThrow(LogicException::class, 'OpenRouter does not support diarized transcription');

    aiAssertHttpNothingSent();
});

test('transcription maps audio mime types to openrouter format values', function (string $mimeType, string $expectedFormat): void {
    aiHttpFake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), $mimeType)->generate(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['input_audio']['format'] === $expectedFormat);
})->with([
    'mp3 via audio/mpeg' => ['audio/mpeg', 'mp3'],
    'mp3 via audio/mp3' => ['audio/mp3', 'mp3'],
    'wav via audio/wav' => ['audio/wav', 'wav'],
    'wav via audio/x-wav' => ['audio/x-wav', 'wav'],
    'm4a via audio/m4a' => ['audio/m4a', 'm4a'],
    'm4a via audio/mp4' => ['audio/mp4', 'm4a'],
    'm4a via audio/x-m4a' => ['audio/x-m4a', 'm4a'],
    'ogg via audio/ogg' => ['audio/ogg', 'ogg'],
    'ogg via audio/ogg opus' => ['audio/ogg; codecs=opus', 'ogg'],
    'flac via audio/flac' => ['audio/flac', 'flac'],
    'flac via audio/x-flac' => ['audio/x-flac', 'flac'],
    'webm via audio/webm' => ['audio/webm', 'webm'],
    'aac via audio/aac' => ['audio/aac', 'aac'],
]);

test('transcription wraps raw pcm audio in a wav header and sends as wav format', function (): void {
    aiHttpFake(['*' => fakeOpenRouterTranscriptionResponse()]);

    $pcm = str_repeat("\x01\x00", 1000);

    Transcription::fromBase64(base64_encode($pcm), 'audio/pcm')->generate(provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request) use ($pcm): bool {
        $body = json_decode($request->body(), true);

        $sent = base64_decode($body['input_audio']['data']);

        return $body['input_audio']['format'] === 'wav'
            && str_starts_with($sent, 'RIFF')
            && substr($sent, 8, 4) === 'WAVE'
            && str_ends_with($sent, $pcm);
    });
});

test('transcription throws invalid argument exception for unsupported mime type', function (): void {
    aiHttpFake();

    expect(fn(): TranscriptionResponse => Transcription::fromBase64(base64_encode('fake-audio'), 'audio/x-aiff')
        ->generate(provider: 'openrouter'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported audio MIME type [audio/x-aiff]');

    aiAssertHttpNothingSent();
});

test('transcription request includes language when specified', function (): void {
    aiHttpFake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('fr')
        ->generate(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['language'] === 'fr');
});

test('transcription request omits language when not specified', function (): void {
    aiHttpFake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => ! array_key_exists('language', json_decode($request->body(), true)));
});

test('transcription uses default model when none specified', function (): void {
    aiHttpFake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model'] === 'openai/whisper-1');
});

test('transcription response text is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeOpenRouterTranscriptionResponse('Hello, world!')]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('openrouter')
        ->and($response->meta->model)->toBe('openai/whisper-1');
});

test('transcription usage is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'text' => 'Hello',
        'usage' => [
            'seconds' => 2.0,
            'total_tokens' => 150,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost' => 0.0005,
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50);
});

test('transcription request sends bearer token', function (): void {
    aiHttpFake(['*' => fakeOpenRouterTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')->generate(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('transcription rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'message' => 'Rate limit exceeded',
                'code' => 429,
            ],
        ], 429),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openrouter', model: 'openai/whisper-1');
})->throws(RateLimitedException::class);

test('transcription overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'message' => 'Service overloaded',
                'code' => 503,
            ],
        ], 503),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openrouter', model: 'openai/whisper-1');
})->throws(ProviderOverloadedException::class);

test('transcription http error response throws request exception', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'error' => [
                'message' => 'Invalid audio format',
                'code' => 400,
            ],
        ], 400),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openrouter', model: 'openai/whisper-1');
})->throws(RequestException::class);
