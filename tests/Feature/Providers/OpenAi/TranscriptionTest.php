<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\ProviderOverloadedException;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Transcription;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('transcription sends prompt from provider options', function (): void {
    aiHttpFake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['prompt' => 'CakePHP Bake and DebugKit'])
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
        && str_contains($request->body(), 'prompt')
        && str_contains($request->body(), 'CakePHP Bake and DebugKit'));
});

test('transcription throws when prompt provider option is used with diarized models', function (): void {
    aiHttpFake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['prompt' => 'CakePHP Bake and DebugKit'])
        ->diarize()
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');
})->throws(LogicException::class, 'OpenAI does not support the `prompt` option for diarized transcriptions.');

test('transcription request posts to correct endpoint', function (): void {
    aiHttpFake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
        && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
});

test('transcription includes model in request', function (): void {
    aiHttpFake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'gpt-4o-transcribe'));
});

test('transcription strips diarize suffix from model when diarize is off', function (): void {
    aiHttpFake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'gpt-4o-transcribe')
        && ! str_contains($request->body(), 'gpt-4o-transcribe-diarize'));
});

test('transcription response text is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeOpenAiTranscriptionResponse('Hello, world!')]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->meta->provider)->toBe('openai');
});

test('transcription usage is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'text' => 'Hello',
        'usage' => [
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    expect($response->usage->inputTokens)->toBe(100)
        ->and($response->usage->outputTokens)->toBe(50);
});

test('transcription sends language when provided', function (): void {
    aiHttpFake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('en')
        ->generate(provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'language')
        && str_contains($request->body(), 'en'));
});

test('transcription request sends bearer token', function (): void {
    aiHttpFake(['*' => fakeOpenAiTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('transcription rate limit response throws rate limited exception', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');
})->throws(RateLimitedException::class);

test('transcription overloaded response throws provider overloaded exception', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded.',
            ],
        ], 503),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');
})->throws(ProviderOverloadedException::class);

test('transcription http error response throws request exception', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid file format',
            ],
        ], 400),
    ]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');
})->throws(RequestException::class);

function fakeOpenAiTranscriptionResponse(string $text = 'Hello, world!'): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'text' => $text,
        'usage' => [
            'input_tokens' => 10,
            'total_tokens' => 15,
        ],
    ]);
}

test('transcription reports the billed audio seconds for duration based models', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'text' => 'Hello, world!',
        'usage' => ['type' => 'duration', 'seconds' => 12.5],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'whisper-1');

    expect($response->usage->audioSeconds)->toBe(12.5)
        ->and($response->usage->inputTokens)->toBe(0);
});

test('transcription leaves the audio seconds null for token based models', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'text' => 'Hello, world!',
        'usage' => [
            'type' => 'tokens',
            'input_tokens' => 14,
            'output_tokens' => 4,
            'input_token_details' => ['text_tokens' => 0, 'audio_tokens' => 14],
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe');

    expect($response->usage->audioSeconds)->toBeNull()
        ->and($response->usage->inputTokens)->toBe(14);
});

test('diarized transcription reports the audio duration when usage is token based', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'task' => 'transcribe',
        'duration' => 42.7,
        'text' => 'Hello, world!',
        'segments' => [],
        'usage' => ['type' => 'tokens', 'input_tokens' => 14, 'output_tokens' => 4, 'total_tokens' => 18],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'openai', model: 'gpt-4o-transcribe-diarize');

    expect($response->usage->audioSeconds)->toBe(42.7)
        ->and($response->usage->inputTokens)->toBe(14);
});
