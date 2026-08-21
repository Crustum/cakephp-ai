<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Audio;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.gemini', [

        ...(array)Configure::read('Ai.providers.gemini'),
        'key' => 'test-key',
    ]);
});

test('audio request includes model, prompt text, and voice name', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')
        ->voice('Kore')
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = $request->data();

        return str_contains($request->url(), 'models/gemini-2.5-flash-preview-tts:generateContent')
            && $body['contents'][0]['parts'][0]['text'] === 'Hello world'
            && $body['generationConfig']['responseModalities'] === ['AUDIO']
            && $body['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore';
    });
});

test('audio request resolves default voice aliases', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');
    Audio::of('Hello world')->male()->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->data()['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore');
    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->data()['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Puck');
});

test('audio instructions are prepended to the prompt instead of sent in speech config', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Have a wonderful day!')
        ->voice('Kore')
        ->instructions('Say cheerfully:')
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = $request->data();
        $speechConfig = $body['generationConfig']['speechConfig'];

        return $body['contents'][0]['parts'][0]['text'] === "Say cheerfully:\n\nHave a wonderful day!"
            && ! isset($speechConfig['instructions'])
            && ! isset($speechConfig['voiceConfig']['instructions'])
            && ! isset($speechConfig['voiceConfig']['prebuiltVoiceConfig']['instructions']);
    });
});

test('audio response is wrapped as wav with correct meta', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse("\x01\x00\x02\x00"),
    ]);

    $response = Audio::of('Hello world')
        ->voice('Kore')
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    expect($response->mimeType())->toBe('audio/wav')
        ->and(substr($response->content(), 0, 4))->toBe('RIFF')
        ->and(substr($response->content(), 8, 4))->toBe('WAVE')
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-2.5-flash-preview-tts');
});

test('audio request passes custom voice name through unchanged', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')
        ->voice('my-custom-voice')
        ->generate(provider: 'gemini', model: 'gemini-2.5-flash-preview-tts');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->data()['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'my-custom-voice');
});

test('audio uses default model when none specified', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => fakeGeminiAudioResponse(),
    ]);

    Audio::of('Hello world')->voice('Kore')->generate(provider: 'gemini');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_contains($request->url(), 'models/gemini-2.5-flash-preview-tts:generateContent'));
});

function fakeGeminiAudioResponse(string $pcm = "\x00\x00"): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'candidates' => [[
            'content' => [
                'parts' => [[
                    'inlineData' => [
                        'data' => base64_encode($pcm),
                        'mimeType' => 'audio/pcm',
                    ],
                ]],
                'role' => 'model',
            ],
            'finishReason' => 'STOP',
        ]],
    ]);
}
