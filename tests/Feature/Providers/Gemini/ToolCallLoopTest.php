<?php
declare(strict_types=1);

use Crustum\Ai\Exception\NoSuchToolException;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Test\Fixtures\Agents\MultiStepToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\NamedToolAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;

function geminiFollowUpSteps(iterable $recorded, string $type): array
{
    return array_values(array_filter(
        $recorded[1][0]->data()['input'],
        fn(array $step): bool => ($step['type'] ?? null) === $type,
    ));
}

test('tool calls trigger follow up request', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'gemini',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2)
        ->and(geminiFollowUpSteps($recorded, 'function_call'))->toHaveCount(1)
        ->and(geminiFollowUpSteps($recorded, 'function_result'))->toHaveCount(1);
});

test('max steps limits tool call depth', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'gemini',
    );

    expect(count(aiHttpRecorded()))->toBeLessThanOrEqual(3);
});

test('multi step tool loop returns accumulated response shape', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent())->prompt(
        'Generate numbers',
        provider: 'gemini',
    );

    expect((string)$response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->inputTokens)->toBe(30)
        ->and($response->usage->outputTokens)->toBe(15);
});

test('unregistered tool call throws', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            $this->fakeToolCallResponse('NonExistentTool', 'call_missing'),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    expect(fn(): AgentResponse => (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini'))
        ->toThrow(NoSuchToolException::class);
});

test('function result carries the originating call id', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_abc123'),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $result = geminiFollowUpSteps($recorded, 'function_result')[0] ?? null;

    expect($result)->not->toBeNull('Follow-up should include a function_result step')
        ->and($result['name'])->toBe('FixedNumberGenerator')
        ->and($result['call_id'])->toBe('call_abc123')
        ->and($result['result'][0]['type'])->toBe('text');
});

test('parallel function calls preserve unique ids', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            aiHttpResponse($this->fakeInteraction([
                $this->functionCallStep('FixedNumberGenerator', [], 'call_1'),
                $this->functionCallStep('FixedNumberGenerator', [], 'call_2'),
            ])),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate two', provider: 'gemini');

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $ids = array_column(geminiFollowUpSteps($recorded, 'function_result'), 'call_id');

    expect($ids)->toHaveCount(2)
        ->toContain('call_1')
        ->toContain('call_2');
});

test('tool declaring a name() method routes the function call back to itself', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            $this->fakeToolCallResponse('aliased_tool', 'call_named_1'),
            $this->fakeTextResponse('done'),
        ]),
    ]);

    (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'gemini');

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $result = geminiFollowUpSteps($recorded, 'function_result')[0] ?? null;

    expect($result)->not->toBeNull('Follow-up should include a function_result for the declared tool name')
        ->and($result['name'])->toBe('aliased_tool');
});

test('thought steps are replayed verbatim across the tool call continuation', function (): void {
    $thought = [
        'type' => 'thought',
        'summary' => [['type' => 'text', 'text' => 'Let me generate that...']],
        'signature' => 'sig_abc_123',
    ];

    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            aiHttpResponse($this->fakeInteraction([
                $thought,
                $this->functionCallStep('FixedNumberGenerator', [], 'call_1'),
            ])),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    // Gemini rejects tool-call history whose thought steps were altered, so they
    // must survive the round trip byte for byte, signature included.
    expect(geminiFollowUpSteps($recorded, 'thought'))->toBe([$thought]);
});

test('the user input echoed by gemini is not replayed twice', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            aiHttpResponse($this->fakeInteraction([
                ['type' => 'user_input', 'content' => [['type' => 'text', 'text' => 'Generate']]],
                $this->functionCallStep('FixedNumberGenerator', [], 'call_1'),
            ])),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

    expect(geminiFollowUpSteps(aiHttpRecorded(), 'user_input'))->toHaveCount(1);
});
