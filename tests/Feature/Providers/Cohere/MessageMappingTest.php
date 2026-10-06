<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Laminas\Diactoros\UploadedFile;

beforeEach(function (): void {
    Configure::write('Ai.providers.cohere', [
        ...(array)Configure::read('Ai.providers.cohere'),
        'key' => 'test-key',
    ]);
});

test('conversation history maps to chat messages', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('Your name is Sam.')]);

    agent('Be concise.', messages: [
        new Message(role: 'user', content: 'My name is Sam.'),
        new Message(role: 'assistant', content: 'Nice to meet you, Sam.'),
    ])->prompt('What is my name?', provider: 'cohere');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['messages'] === [
        ['role' => 'system', 'content' => 'Be concise.'],
        ['role' => 'user', 'content' => 'My name is Sam.'],
        ['role' => 'assistant', 'content' => 'Nice to meet you, Sam.'],
        ['role' => 'user', 'content' => 'What is my name?'],
    ]);
});

test('assistant tool calls and tool results are replayed in the follow up request', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_1'),
            $this->fakeTextResponse('The random number generated is 72019.'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'cohere');

    $messages = json_decode((string)aiHttpRecorded()[1][0]->body(), true)['messages'];

    expect(collect($messages)->filter(fn($m): bool => ($m['role'] ?? null) === 'assistant')->first())->toBe([
        'role' => 'assistant',
        'tool_calls' => [[
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'],
        ]],
    ])->and(collect($messages)->filter(fn($m): bool => ($m['role'] ?? null) === 'tool')->first())->toBe([
        'role' => 'tool',
        'tool_call_id' => 'call_1',
        'content' => '72019',
    ]);
});

test('remote image attachment maps to image url', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new RemoteImage('https://example.com/image.png')],
        provider: 'cohere',
    );

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['messages'][1]['content'] === [
        ['type' => 'text', 'text' => 'What is in this image?'],
        ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png']],
    ]);
});

test('base64 image attachment maps to data uri', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new Base64Image(base64_encode('fake-image-data'), 'image/png')],
        provider: 'cohere',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $content = json_decode($request->body(), true)['messages'][1]['content'];

        return collect($content)->filter(fn($m): bool => ($m['type'] ?? null) === 'image_url')->first() === [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('fake-image-data')],
        ];
    });
});

test('uploaded image maps to data uri', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see an image')]);

    $resource = fopen('php://temp', 'r+');
    fwrite($resource, 'png-bytes');
    rewind($resource);
    $upload = new UploadedFile($resource, 9, UPLOAD_ERR_OK, 'photo.png', 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$upload],
        provider: 'cohere',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $content = json_decode($request->body(), true)['messages'][1]['content'];

        return collect($content)->filter(fn($m): bool => ($m['type'] ?? null) === 'image_url')->first() === [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('png-bytes')],
        ];
    });
});

test('document attachments throw', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse()]);

    expect(fn(): AgentResponse => agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [Document::fromString('contents', 'text/plain')],
        provider: 'cohere',
    ))->toThrow(
        InvalidArgumentException::class,
        'Cohere does not support document attachments. Only image attachments are supported.',
    );

    aiAssertHttpNothingSent();
});
