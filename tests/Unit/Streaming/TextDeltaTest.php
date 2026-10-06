<?php
declare(strict_types=1);

use Crustum\Ai\Responses\Data;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall;
use Crustum\Ai\Streaming\Event\ToolResult;

function textDelta(string $messageId, string $delta): TextDelta
{
    return new TextDelta(uniqid(), $messageId, $delta, time());
}

/**
 * Build the stream start that every gateway yields exactly once at the top of a step.
 */
function stepStart(): StreamStart
{
    return new StreamStart(uniqid(), 'fake', 'fake-model', time());
}

test('combine joins the deltas of a single step without separators', function (): void {
    $events = [
        stepStart(),
        textDelta('message-1', 'Hello'),
        textDelta('message-1', ' there'),
        textDelta('message-1', '!'),
    ];

    expect(TextDelta::combine($events))->toBe('Hello there!');
});

test('combine separates the text of different steps with a blank line', function (): void {
    $events = [
        stepStart(),
        textDelta('message-1', 'Let me look that up.'),
        new ToolCall(uniqid(), new Data\ToolCall('call-1', 'get_weather', ['city' => 'Copenhagen']), time()),
        new ToolResult(uniqid(), new Data\ToolResult('call-1', 'get_weather', ['city' => 'Copenhagen'], '12°C'), true, null, time()),
        stepStart(),
        textDelta('message-2', 'It is '),
        textDelta('message-2', '12°C in Copenhagen.'),
    ];

    expect(TextDelta::combine($events))->toBe("Let me look that up.\n\nIt is 12°C in Copenhagen.");
});

test('combine keeps a step whole when a provider splits it around a citation', function (): void {
    $events = [
        stepStart(),
        textDelta('message-1', 'CakePHP 5 is current, which'),
        textDelta('message-2', ' shipped in September'),
        textDelta('message-3', '.'),
    ];

    expect(TextDelta::combine($events))->toBe('CakePHP 5 is current, which shipped in September.');
});

test('combine drops a step that produced only whitespace', function (): void {
    $events = [
        stepStart(),
        textDelta('message-1', 'First.'),
        stepStart(),
        textDelta('message-2', "\n"),
        stepStart(),
        textDelta('message-3', 'Second.'),
    ];

    expect(TextDelta::combine($events))->toBe("First.\n\nSecond.");
});

test('combine ignores events that are not text deltas', function (): void {
    $events = [
        stepStart(),
        new TextStart(uniqid(), 'message-1', time()),
        textDelta('message-1', 'Only text.'),
        new TextEnd(uniqid(), 'message-1', time()),
    ];

    expect(TextDelta::combine($events))->toBe('Only text.');
});

test('combine joins deltas that arrive before any step start', function (): void {
    $events = [
        textDelta('message-1', 'Hello'),
        textDelta('message-2', ' there!'),
    ];

    expect(TextDelta::combine($events))->toBe('Hello there!');
});

test('combine returns an empty string when there are no text deltas', function (): void {
    expect(TextDelta::combine([]))->toBe('');
});
