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
    Configure::write('Ai.providers.ollama.key', '');
});

test('user message maps to ollama format', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: 'ollama',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $messages = $body['messages'];
        $userMessage = collect($messages)->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();

        return $userMessage !== null
            && $userMessage['content'] === IntegrationPrompts::question('knowledge');
    });
});

test('tool result follow up uses tool_name not tool_call_id', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'ollama',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);
    $followUpMessages = $followUpBody['messages'];

    $toolMsg = collect($followUpMessages)->filter(fn($m): bool => ($m['role'] ?? null) === 'tool')->first();

    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg)->toHaveKey('tool_name')
        ->and($toolMsg)->not->toHaveKey('tool_call_id')
        ->and($toolMsg['tool_name'])->toBe('FixedNumberGenerator');
});

test('assistant tool call message uses function format without type', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'ollama',
    );

    $recorded = aiHttpRecorded();
    $followUpBody = json_decode($recorded[1][0]->body(), true);
    $followUpMessages = $followUpBody['messages'];

    $assistantMsg = collect($followUpMessages)->filter(
        fn($m): bool => ($m['role'] ?? null) === 'assistant' && isset($m['tool_calls']),
    )->first();

    expect($assistantMsg)->not->toBeNull();

    $toolCall = $assistantMsg['tool_calls'][0];

    expect($toolCall)->toHaveKey('function')
        ->and($toolCall)->not->toHaveKey('type')
        ->and($toolCall['function']['name'])->toBe('FixedNumberGenerator');
});

test('image attachment maps to images array with base64', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse('I see an image'),
    ]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'ollama',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();

        return isset($userMessage['images'])
            && is_array($userMessage['images'])
            && $userMessage['images'][0] === base64_encode('fake-image-data');
    });
});

test('document attachments throw exception', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse(),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'ollama',
    );
})->throws(InvalidArgumentException::class);
