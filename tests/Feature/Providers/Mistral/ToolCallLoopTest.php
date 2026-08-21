<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Exception\NoSuchToolException;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.mistral', [

        ...(array)Configure::read('Ai.providers.mistral'),
        'key' => 'test-key',
    ]);
});

test('tool calls trigger follow up request', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'mistral',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);

    $hasAssistantWithToolCalls = false;
    $hasToolResult = false;

    foreach ($followUpBody['messages'] as $message) {
        if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
            $hasAssistantWithToolCalls = true;
        }

        if ($message['role'] === 'tool') {
            $hasToolResult = true;
        }
    }

    expect($hasAssistantWithToolCalls)->toBeTrue()
        ->and($hasToolResult)->toBeTrue();
});

test('max steps limits tool call depth', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'mistral',
    );

    $recorded = aiHttpRecorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('multi step tool loop returns accumulated response shape', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent())->prompt(
        'Generate numbers',
        provider: 'mistral',
    );

    expect((string)$response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->promptTokens)->toBe(30)
        ->and($response->usage->completionTokens)->toBe(15);
});

test('unregistered tool call throws', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse('NonExistentTool', 'call_' . uniqid()),
        ]),
    ]);

    expect(fn(): AgentResponse => (new MultiStepToolAgent())->prompt(
        'Generate numbers',
        provider: 'mistral',
    ))->toThrow(NoSuchToolException::class);
});

test('follow up request includes original messages', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'mistral',
    );

    $recorded = aiHttpRecorded();

    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);

    $userMsg = collect($followUpBody['messages'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();

    expect($userMsg)->not->toBeNull();
});

test('follow up response with block content is flattened to text', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
            $this->fakeTextResponse([
                ['type' => 'text', 'text' => 'The number is 72019'],
                ['type' => 'reference', 'reference_ids' => ['search_documents']],
            ]),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'mistral',
    );

    expect($response->text)->toBe('The number is 72019');
});
