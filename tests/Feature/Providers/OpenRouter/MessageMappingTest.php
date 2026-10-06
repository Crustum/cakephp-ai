<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Files\Audio;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\ProviderDocument;
use Crustum\Ai\Files\ProviderImage;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Laminas\Diactoros\UploadedFile;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

test('user message maps to chat completions format', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello there', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['messages'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();

        return $userMsg !== null
            && $userMsg['content'] === 'Hello there';
    });
});

test('system instructions are sent as system role message', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    (new AssistantAgent())->prompt('Hello', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $body['messages'][0]['role'] === 'system'
            && str_contains($body['messages'][0]['content'], 'helpful assistant');
    });
});

test('tool result follow up maps assistant and tool result messages', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('The number is 72019'),
        ]),
    ]);

    agent(tools: [new FixedNumberGenerator()])->prompt('Give me a number', provider: 'openrouter');

    $requests = aiHttpRecorded(fn(AiHttpRequest $r): true => true);
    $followUpBody = json_decode($requests[1][0]->body(), true);
    $messages = $followUpBody['messages'];

    $assistantMsg = collect($messages)->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'assistant')->first();
    expect($assistantMsg)->not->toBeNull()
        ->and($assistantMsg)->toHaveKey('tool_calls')
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator');

    $toolMsg = collect($messages)->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'tool')->first();
    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg['tool_call_id'])->toBe('call_123');
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
        provider: 'openrouter',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['messages'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
        $imageBlock = collect($userMsg['content'])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'image_url')->first();

        return $imageBlock !== null
            && str_starts_with($imageBlock['image_url']['url'], 'data:image/png;base64,')
            && ! str_contains($imageBlock['image_url']['url'], 'data:;base64,');
    });
});

test('base64 document without an explicit name falls back to a mime-based filename', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('I see a document')]);

    $document = new Base64Document(base64_encode('fake-pdf-data'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [$document],
        provider: 'openrouter',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['messages'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
        $fileBlock = collect($userMsg['content'])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'file')->first();

        return $fileBlock !== null
            && $fileBlock['file']['filename'] === 'document.pdf';
    });
});

/**
 * @return array<string, mixed>|null
 */
function openRouterAudioPart(): ?array
{
    $recorded = aiHttpRecorded(fn(AiHttpRequest $r): bool => str_starts_with($r->url(), 'https://openrouter.ai'));
    $request = $recorded[0][0];
    $body = json_decode($request->body(), true);
    $userMsg = collect($body['messages'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();

    return collect($userMsg['content'])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'input_audio')->first();
}

test('base64 audio attachment maps to input_audio with format from mime type', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Heard it')]);

    agent()->prompt(
        'Transcribe this.',
        attachments: [Audio::fromBase64(base64_encode('fake-audio'), 'audio/mpeg')],
        provider: 'openrouter',
    );

    expect(openRouterAudioPart())->toBe([
        'type' => 'input_audio',
        'input_audio' => ['format' => 'mp3', 'data' => base64_encode('fake-audio')],
    ]);
});

test('remote audio attachment is downloaded and sent as base64', function (): void {
    aiHttpFake([
        'example.com/*' => aiHttpResponse('wav-bytes', 200, ['Content-Type' => 'audio/wav']),
        'openrouter.ai/*' => fakeOpenRouterResponse('Heard it'),
    ]);

    agent()->prompt(
        'Transcribe this.',
        attachments: [Audio::fromUrl('https://example.com/note.wav')],
        provider: 'openrouter',
    );

    expect(openRouterAudioPart()['input_audio'])->toBe(['format' => 'wav', 'data' => base64_encode('wav-bytes')]);
});

test('uploaded audio file maps to input_audio', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Heard it')]);

    $resource = fopen('php://temp', 'r+');
    fwrite($resource, 'mp3-bytes');
    rewind($resource);

    agent()->prompt(
        'Transcribe this.',
        attachments: [new UploadedFile($resource, 9, UPLOAD_ERR_OK, 'note.mp3', 'audio/mpeg')],
        provider: 'openrouter',
    );

    expect(openRouterAudioPart()['input_audio'])->toBe(['format' => 'mp3', 'data' => base64_encode('mp3-bytes')]);
});

test('provider stored attachments are rejected', function (mixed $attachment): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('What is in this file?', attachments: [$attachment], provider: 'openrouter');
})->with([
    'document' => fn(): ProviderDocument => new ProviderDocument('or_file_abc123'),
    'image' => fn(): ProviderImage => new ProviderImage('or_file_abc123'),
])->throws(
    InvalidArgumentException::class,
    'Provider-stored attachments are not supported by OpenRouter; uploaded files may only be loaded into a sandbox container by the shell tool.',
);
