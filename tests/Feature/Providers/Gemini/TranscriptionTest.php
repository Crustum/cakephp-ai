<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Transcription;

beforeEach(function (): void {
    Configure::write('Ai.providers.gemini', [

        ...(array)Configure::read('Ai.providers.gemini'),
        'key' => 'test-key',
    ]);
});

test('transcription request sends audio as inline data with correct mime type', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = $request->data();
        $parts = $body['contents'][0]['parts'];

        return str_contains($request->url(), 'models/gemini-3.7-flash:generateContent')
            && $parts[1]['inlineData']['mimeType'] === 'audio/mp3'
            && $parts[1]['inlineData']['data'] === base64_encode('fake-audio');
    });
});

test('transcription request includes language in prompt when specified', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))
        ->language('fr')
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains(
        (string)$request->data()['contents'][0]['parts'][0]['text'],
        'fr',
    ));
});

test('transcription response returns text with correct meta', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    expect($response->text)->toBe('Hello world')
        ->and($response->segments)->toHaveCount(0)
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-3.7-flash')
        ->and($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('transcription uses default model when none specified', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))->generate(provider: 'gemini');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->url(), 'models/gemini-3.5-flash:generateContent'));
});

test('diarized transcription request sends json schema in generation config', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse(),
    ]);

    Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = $request->data();

        return isset($body['generationConfig']['responseMimeType'])
            && $body['generationConfig']['responseMimeType'] === 'application/json'
            && isset($body['generationConfig']['responseSchema']['properties']['segments'])
            && str_contains((string)$body['contents'][0]['parts'][0]['text'], 'MM:SS or HH:MM:SS');
    });
});

test('diarized transcription response returns text and segments', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse(),
    ]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    expect($response->text)->toBe('Hello world')
        ->and($response->segments)->toHaveCount(2)
        ->and($response->segments->toList()[0]->text)->toBe('Hello')
        ->and($response->segments->toList()[0]->startSeconds)->toBe(0.0)
        ->and($response->segments->toList()[0]->endSeconds)->toBe(2.0)
        ->and($response->segments->toList()[1]->text)->toBe('world')
        ->and($response->segments->toList()[1]->startSeconds)->toBe(2.0)
        ->and($response->segments->toList()[1]->endSeconds)->toBe(4.0)
        ->and($response->usage->promptTokens)->toBe(42)
        ->and($response->usage->completionTokens)->toBe(20);
});

test('diarized transcription parses srt and hour timestamp formats', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiDiarizedTranscriptionResponse([
            ['text' => 'Hello', 'start_time' => '00:00:01,500', 'end_time' => '00:01:02,250'],
            ['text' => 'world', 'start_time' => '01:02:03.750', 'end_time' => '3724.25'],
        ]),
    ]);

    $response = Transcription::of(base64_encode('fake-audio'))
        ->diarize()
        ->generate(provider: 'gemini', model: 'gemini-3.7-flash');

    expect($response->segments->toList()[0]->startSeconds)->toBe(1.5)
        ->and($response->segments->toList()[0]->endSeconds)->toBe(62.25)
        ->and($response->segments->toList()[1]->startSeconds)->toBe(3723.75)
        ->and($response->segments->toList()[1]->endSeconds)->toBe(3724.25);
});

function fakeGeminiTranscriptionResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'candidates' => [[
            'content' => [
                'parts' => [['text' => 'Hello world']],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => [
            'promptTokenCount' => 10,
            'candidatesTokenCount' => 5,
            'totalTokenCount' => 15,
        ],
    ]);
}

function fakeGeminiDiarizedTranscriptionResponse(?array $segments = null): AiHttpResponseDefinition
{
    $segments ??= [
        ['text' => 'Hello', 'start_time' => '0:00', 'end_time' => '0:02'],
        ['text' => 'world', 'start_time' => '0:02', 'end_time' => '0:04'],
    ];

    return aiHttpResponse([
        'candidates' => [[
            'content' => [
                'parts' => [['text' => json_encode([
                    'transcript' => 'Hello world',
                    'segments' => $segments,
                ])]],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => [
            'promptTokenCount' => 42,
            'candidatesTokenCount' => 20,
            'totalTokenCount' => 62,
        ],
    ]);
}
