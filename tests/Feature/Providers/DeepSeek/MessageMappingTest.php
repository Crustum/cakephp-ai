<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\IntegrationPrompts;

beforeEach(function (): void {
    Configure::write('Ai.providers.deepseek', [

        ...(array)Configure::read('Ai.providers.deepseek'),
        'key' => 'test-key',
    ]);
});

test('user message maps to deepseek format', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => fakeDeepSeekResponse(),
    ]);

    (new AssistantAgent())->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: 'deepseek',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();

        return $userMessage !== null
            && $userMessage['content'] === IntegrationPrompts::question('knowledge');
    });
});

test('tool result follow up maps assistant and tool result messages', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => aiHttpSequence([
            fakeDeepSeekToolCallResponse(),
            fakeDeepSeekResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'deepseek',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);
    $followUpMessages = $followUpBody['messages'];

    $hasAssistantWithToolCalls = false;
    $hasToolResult = false;

    foreach ($followUpMessages as $msg) {
        if ($msg['role'] === 'assistant' && isset($msg['tool_calls'])) {
            $hasAssistantWithToolCalls = true;
        }

        if ($msg['role'] === 'tool') {
            $hasToolResult = true;
        }
    }

    expect($hasAssistantWithToolCalls)->toBeTrue()
        ->and($hasToolResult)->toBeTrue();

    $assistantMsg = collect($followUpMessages)->filter(fn($m): bool => $m['role'] === 'assistant' && isset($m['tool_calls']))->last();
    $toolMsg = collect($followUpMessages)->filter(fn($m): bool => $m['role'] === 'tool')->last();

    expect($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator')
        ->and($toolMsg['tool_call_id'])->toBe($assistantMsg['tool_calls'][0]['id'])
        ->and($toolMsg['content'])->not->toBeEmpty();
});

test('image attachment maps to image url content block', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => fakeDeepSeekResponse('I see an image'),
    ]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'deepseek',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();
        $content = $userMessage['content'];

        $imageBlock = collect($content)->filter(fn($m): bool => ($m['type'] ?? null) === 'image_url')->first();

        return $imageBlock !== null
            && str_contains((string)$imageBlock['image_url']['url'], 'image/png')
            && str_contains((string)$imageBlock['image_url']['url'], base64_encode('fake-image-data'));
    });
});

test('document attachments throw exception', function (): void {
    aiHttpFake([
        'api.deepseek.com/*' => fakeDeepSeekResponse(),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'deepseek',
    );
})->throws(InvalidArgumentException::class);
