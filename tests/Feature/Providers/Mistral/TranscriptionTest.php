<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Transcription;

beforeEach(function (): void {
    Configure::write('Ai.providers.mistral', [

        ...(array)Configure::read('Ai.providers.mistral'),
        'key' => 'test-key',
    ]);
});

test('transcription request posts to correct endpoint', function (): void {
    aiHttpFake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://api.mistral.ai/v1/audio/transcriptions'
        && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
});

test('transcription response text is correctly parsed', function (): void {
    aiHttpFake(['*' => fakeTranscriptionResponse('Hello, world!')]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    expect($response->text)->toBe('Hello, world!')
        ->and($response->meta->provider)->toBe('mistral');
});

test('transcription includes model in request', function (): void {
    aiHttpFake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'voxtral-mini-latest'));
});

test('transcription sends language when provided', function (): void {
    aiHttpFake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('en')
        ->generate(provider: 'mistral');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'language')
        && str_contains($request->body(), 'en'));
});

test('transcription sends context bias from provider options', function (): void {
    aiHttpFake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['context_bias' => 'CakePHP,Bake'])
        ->generate(provider: 'mistral');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->body(), 'context_bias')
        && str_contains($request->body(), 'CakePHP,Bake'));
});

test('transcription sends context bias array as repeated parts', function (): void {
    aiHttpFake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->withProviderOptions(['context_bias' => ['CakePHP', 'Bake']])
        ->generate(provider: 'mistral');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = $request->body();

        return substr_count($body, 'name="context_bias"') === 2
            && str_contains($body, 'CakePHP')
            && str_contains($body, 'Bake');
    });
});

test('transcription sends bearer token', function (): void {
    aiHttpFake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('transcription usage is correctly parsed', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'text' => 'Hello',
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50);
});

test('transcription omits language and sends diarize flag when diarizing', function (): void {
    aiHttpFake(['*' => fakeTranscriptionResponse()]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->language('en')
        ->diarize()
        ->generate(provider: 'mistral');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = $request->body();

        return str_contains($body, 'diarize')
            && str_contains($body, 'name="timestamp_granularities"')
            && ! str_contains($body, 'timestamp_granularities[')
            && str_contains($body, 'segment')
            && ! str_contains($body, 'language');
    });
});

test('transcription response segments are parsed when diarizing', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'text' => 'Hello world',
        'segments' => [
            ['text' => 'Hello', 'speaker_id' => 'speaker_0', 'start' => 0.0, 'end' => 0.5],
            ['text' => 'world', 'speaker_id' => 'speaker_1', 'start' => 0.6, 'end' => 1.0],
        ],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    $response = Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->diarize()
        ->generate(provider: 'mistral');

    expect($response->segments)->toHaveCount(2)
        ->and($response->segments->first()->text)->toBe('Hello')
        ->and($response->segments->first()->speaker)->toBe('speaker_0')
        ->and($response->segments->first()->startSeconds)->toBe(0.0)
        ->and($response->segments->toList()[1]->text)->toBe('world')
        ->and($response->segments->toList()[1]->speaker)->toBe('speaker_1');
});

test('transcription throws when the API returns an error', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['message' => 'Unauthorized'], 401)]);

    Transcription::fromBase64(base64_encode('fake-audio'), 'audio/mp3')
        ->generate(provider: 'mistral');
})->throws(RequestException::class);

function fakeTranscriptionResponse(string $text = 'Hello, world!'): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'text' => $text,
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}
