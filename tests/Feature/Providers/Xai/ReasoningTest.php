<?php
declare(strict_types=1);

use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

test('prompt reads reasoning off the response', function (array $item, string $expected): void {
    aiHttpFake(['*' => $this->fakeReasonedTextResponse([$item])]);

    expect((new AssistantAgent())->prompt('Hi', provider: 'xai')->reasoning)->toBe($expected);
})->with([
    'reasoning summary' => [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [
            ['type' => 'summary_text', 'text' => 'Let me '],
            ['type' => 'summary_text', 'text' => 'think...'],
        ]],
        'Let me think...',
    ],
    'reasoning text' => [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'content' => [
            ['type' => 'reasoning_text', 'text' => 'Raw thoughts.'],
        ]],
        'Raw thoughts.',
    ],
]);

test('prompt reads the answer and finish reason whichever side of the message the reasoning sits on', function (bool $reasoningFirst): void {
    aiHttpFake(['*' => $this->fakeReasonedTextResponse([
        ['type' => 'reasoning', 'id' => 'rs_1', 'status' => 'completed', 'summary' => [
            ['type' => 'summary_text', 'text' => 'Let me think...'],
        ]],
    ], 'The answer is 303.', $reasoningFirst)]);

    $response = (new AssistantAgent())->prompt('Hi', provider: 'xai');

    expect($response->text)->toBe('The answer is 303.')
        ->and($response->reasoning)->toBe('Let me think...')
        ->and($response->steps->last()->finishReason)->toBe(FinishReason::Stop);
})->with(['reasoning last' => false, 'reasoning first' => true]);

test('a summary and the raw reasoning of one item stay separate blocks', function (): void {
    aiHttpFake(['*' => $this->fakeReasonedTextResponse([
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [
            ['type' => 'summary_text', 'text' => 'I weighed the options.'],
        ], 'content' => [
            ['type' => 'reasoning_text', 'text' => 'Raw thoughts.'],
        ]],
    ])]);

    expect((new AssistantAgent())->prompt('Hi', provider: 'xai')->reasoning)->toBe("I weighed the options.\n\nRaw thoughts.");
});

test('prompt separates each reasoning block with a blank line', function (): void {
    aiHttpFake(['*' => $this->fakeReasonedTextResponse([
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'First.']]],
        ['type' => 'reasoning', 'id' => 'rs_2', 'summary' => [['type' => 'summary_text', 'text' => '   ']]],
        ['type' => 'reasoning', 'id' => 'rs_3', 'summary' => [['type' => 'summary_text', 'text' => 'Second.']]],
    ])]);

    expect((new AssistantAgent())->prompt('Hi', provider: 'xai')->reasoning)->toBe("First.\n\nSecond.");
});
