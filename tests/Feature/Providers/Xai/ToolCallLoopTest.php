<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;

beforeEach(function (): void {
    Configure::write('Ai.providers.xai', [

        ...(array)Configure::read('Ai.providers.xai'),
        'key' => 'test-key',
    ]);
});

test('tool calls trigger follow up request', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'xai',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id');

    $hasToolOutput = false;

    foreach ($followUpBody['input'] as $item) {
        if (($item['type'] ?? '') === 'function_call_output') {
            $hasToolOutput = true;
        }
    }

    expect($hasToolOutput)->toBeTrue('Follow-up request should include function_call_output');
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
        provider: 'xai',
    );

    $recorded = aiHttpRecorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('follow up request preserves tools', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'xai',
    );

    $recorded = aiHttpRecorded();

    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('tools')
        ->and($followUpBody['tools'])->not->toBeEmpty();
});
