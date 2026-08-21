<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\IntegrationPrompts;

beforeEach(function (): void {
    Configure::write('Ai.providers.mistral', [

        ...(array)Configure::read('Ai.providers.mistral'),
        'key' => 'test-key',
    ]);
});

test('user message maps to chat format', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent())->prompt(IntegrationPrompts::question('knowledge'), provider: 'mistral');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();

        return $userMsg['content'] === IntegrationPrompts::question('knowledge');
    });
});

test('assistant message with tool calls maps correctly', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'mistral');

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);

    $assistantMsg = collect($followUpBody['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'assistant')->first();
    $toolMsg = collect($followUpBody['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'tool')->first();

    expect($assistantMsg)->not->toBeNull()
        ->and($toolMsg)->not->toBeNull()
        ->and($assistantMsg['tool_calls'])->not->toBeEmpty()
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator')
        ->and($toolMsg['tool_call_id'])->toBe($assistantMsg['tool_calls'][0]['id']);
});

test('image attachment maps to image url', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see an image')]);

    $image = new RemoteImage('https://example.com/image.png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'mistral',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        if (! is_array($content)) {
            return false;
        }

        $imageBlock = collect($content)->filter(fn($m): bool => ($m['type'] ?? null) === 'image_url')->first();

        return $imageBlock !== null
            && $imageBlock['image_url']['url'] === 'https://example.com/image.png';
    });
});

test('base64 image attachment maps to data uri', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see an image')]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'mistral',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        if (! is_array($content)) {
            return false;
        }

        $imageBlock = collect($content)->filter(fn($m): bool => ($m['type'] ?? null) === 'image_url')->first();

        return $imageBlock !== null
            && str_starts_with((string)$imageBlock['image_url']['url'], 'data:image/png;base64,');
    });
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
        provider: 'mistral',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        if (! is_array($content)) {
            return false;
        }

        $imageBlock = collect($content)->filter(fn($m): bool => ($m['type'] ?? null) === 'image_url')->first();

        return $imageBlock !== null
            && str_starts_with((string)$imageBlock['image_url']['url'], 'data:image/png;base64,')
            && ! str_contains((string)$imageBlock['image_url']['url'], 'data:;base64,');
    });
});

test('remote document maps to document url', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see a document')]);

    $document = new RemoteDocument('https://example.com/report.pdf');

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [$document],
        provider: 'mistral',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        if (! is_array($content)) {
            return false;
        }

        $docBlock = collect($content)->filter(fn($m): bool => ($m['type'] ?? null) === 'document_url')->first();

        return $docBlock !== null
            && $docBlock['document_url'] === 'https://example.com/report.pdf';
    });
});

test('system instructions are in messages array', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent())->prompt('Hi', provider: 'mistral');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        $systemMsg = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'helpful assistant');
    });
});
