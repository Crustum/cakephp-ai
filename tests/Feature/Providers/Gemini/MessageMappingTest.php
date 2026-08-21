<?php
declare(strict_types=1);

use Cake\Collection\Collection;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Files;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\Base64Video;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Support\IntegrationPrompts;
use Crustum\Ai\Test\Support\Storage\LocalDisk;
use Crustum\Ai\Trait\PromptableTrait;

test('user message maps to gemini format', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: 'gemini',
    );

    aiAssertHttpSent(function ($request): bool {
        $contents = $request->data()['contents'];
        $userMessage = $contents[0];

        return $userMessage['role'] === 'user'
            && $userMessage['parts'][0]['text'] === IntegrationPrompts::question('knowledge');
    });
});

test('tool result follow up maps model and function response', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 72019'),
    ]);

    $agent = new class implements Agent, Conversational
    {
        use PromptableTrait;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function messages(): iterable
        {
            // Use non-sequential keys to ensure parts serialize as a JSON array
            $toolResults = collect([
                'custom_key' => new ToolResult('call_123', 'FixedNumberGenerator', [], 123),
            ]);

            return [
                new Message(role: 'user', content: 'Generate a number'),
                new AssistantMessage('', collect([
                    new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123'),
                ])),
                new ToolResultMessage($toolResults),
            ];
        }
    };

    $agent->prompt('Follow up', provider: 'gemini');

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(1);

    $contents = $recorded[0][0]->data()['contents'];

    $modelFunctionCall = null;
    $userMessageParts = null;

    foreach ($contents as $content) {
        if ($content['role'] === 'model') {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionCall'])) {
                    $modelFunctionCall = $part['functionCall'];
                }
            }
        }

        if ($content['role'] === 'user') {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionResponse'])) {
                    $userMessageParts = $content['parts'];
                }
            }
        }
    }

    expect($modelFunctionCall)->not->toBeNull('Follow-up should include model message with functionCall')
        ->and($modelFunctionCall)->not->toHaveKey('args')
        ->and($modelFunctionCall)->not->toHaveKey('id')
        ->and($userMessageParts)->not->toBeNull('Follow-up should include user message with functionResponse')
        ->and(array_is_list($userMessageParts))->toBeTrue('Tool result parts must be a sequential array');
});

test('prior assistant tool call with empty arguments omits args in conversation history', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('OK'),
    ]);

    $agent = new class implements Agent, Conversational
    {
        use PromptableTrait;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function messages(): iterable
        {
            return [
                new Message(role: 'user', content: 'Generate a number'),
                new AssistantMessage('', new Collection([
                    new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123'),
                ])),
            ];
        }
    };

    $agent->prompt('And again', provider: 'gemini');

    aiAssertHttpSent(function ($request): bool {
        $modelFunctionCall = null;

        foreach ($request->data()['contents'] ?? [] as $content) {
            if (($content['role'] ?? null) !== 'model') {
                continue;
            }

            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionCall'])) {
                    $modelFunctionCall = $part['functionCall'];
                }
            }
        }

        $modelFunctionCall ??= null;

        return $modelFunctionCall !== null
            && ! array_key_exists('args', $modelFunctionCall);
    });
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
        provider: 'gemini',
    );

    aiAssertHttpSent(function ($request): bool {
        $parts = $request->data()['contents'][0]['parts'];

        foreach ($parts as $part) {
            if (isset($part['inlineData'])) {
                return $part['inlineData']['mimeType'] === 'image/png';
            }
        }

        return false;
    });
});

test('base64 pdf document maps to inline data', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see a PDF'),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'gemini',
    );

    aiAssertHttpSent(function ($request): bool {
        $parts = $request->data()['contents'][0]['parts'];

        foreach ($parts as $part) {
            if (isset($part['inlineData'])) {
                return $part['inlineData']['mimeType'] === 'application/pdf'
                    && $part['inlineData']['data'] === base64_encode('fake-pdf-content');
            }
        }

        return false;
    });
});

test('base64 video attachment maps to inline data', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see a video'),
    ]);

    $video = new Base64Video(base64_encode('fake-video-content'), 'video/mp4');

    agent('You are helpful.')->prompt(
        'What is in this video?',
        attachments: [$video],
        provider: 'gemini',
    );

    aiAssertHttpSent(function ($request): bool {
        $parts = $request->data()['contents'][0]['parts'];

        foreach ($parts as $part) {
            if (isset($part['inlineData'])) {
                return $part['inlineData']['mimeType'] === 'video/mp4'
                    && $part['inlineData']['data'] === base64_encode('fake-video-content');
            }
        }

        return false;
    });
});

test('stored text document sends real mime type', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    LocalDisk::put('docs', 'notes.txt', 'stored text contents');

    try {
        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [Files\Document::fromStorage('notes.txt', 'docs')],
            provider: 'gemini',
        );

        aiAssertHttpSent(function ($request): bool {
            $parts = $request->data()['contents'][0]['parts'];

            foreach ($parts as $part) {
                if (isset($part['inlineData'])) {
                    return $part['inlineData']['mimeType'] === 'text/plain'
                        && $part['inlineData']['data'] === base64_encode('stored text contents');
                }
            }

            return false;
        });
    } finally {
        LocalDisk::cleanup('docs');
    }
});

test('system instructions are not in contents array', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'gemini',
    );

    aiAssertHttpSent(function ($request): bool {
        $body = $request->data();

        foreach ($body['contents'] as $content) {
            if ($content['role'] === 'system') {
                return false;
            }
        }

        return isset($body['system_instruction']);
    });
});
