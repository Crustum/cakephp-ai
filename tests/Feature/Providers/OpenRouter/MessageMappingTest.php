<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

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
