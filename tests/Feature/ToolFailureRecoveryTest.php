<?php
declare(strict_types=1);

use Cake\Event\EventManager;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Crustum\Ai\Event\ToolFailed;
use Crustum\Ai\Exception\ToolExecutionException;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolResult;
use Crustum\Ai\Test\Fixtures\Agents\ThrowingToolAgent;

beforeEach(function (): void {
    Log::setConfig('tool_failure_test', ['className' => ArrayLog::class, 'levels' => ['error']]);
});

afterEach(function (): void {
    Log::drop('tool_failure_test');
});

function toolFailureLogs(): array
{
    /** @var \Cake\Log\Engine\ArrayLog $engine */
    $engine = Log::engine('tool_failure_test');

    return $engine->read();
}

test('a throwing tool returns a failed result to the model', function (): void {
    ThrowingToolAgent::fake([
        new ToolCall('call-1', 'FixedNumberGenerator', []),
        'The tool could not run.',
    ]);

    $response = (new ThrowingToolAgent())->prompt('Generate');

    expect($response->text)->toBe('The tool could not run.')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->successful())->toBeFalse()
        ->and($response->toolResults->first()->error())->toBe('The tool call failed: Forced to throw exception.')
        ->and(toolFailureLogs())->toHaveCount(1);
});

test('a throwing tool streams a failed result', function (): void {
    ThrowingToolAgent::fake([
        new ToolCall('call-1', 'FixedNumberGenerator', []),
        'The tool could not run.',
    ]);

    $events = iterator_to_array((new ThrowingToolAgent())->stream('Generate'), false);

    $toolResults = array_values(array_filter($events, fn($event): bool => $event instanceof ToolResult));

    expect($toolResults)->toHaveCount(1)
        ->and($toolResults[0]->successful)->toBeFalse()
        ->and($toolResults[0]->error)->toBe('The tool call failed: Forced to throw exception.')
        ->and(TextDelta::combine(array_filter($events, fn($event): bool => $event instanceof TextDelta)))->toBe('The tool could not run.')
        ->and(toolFailureLogs())->toHaveCount(1);
});

test('an exception from a tool event listener still fails the run', function (): void {
    $listener = function (): void {
        throw new RuntimeException('Listener failed.');
    };

    EventManager::instance()->on('Ai.invokingTool', $listener);

    try {
        ThrowingToolAgent::fake([
            new ToolCall('call-1', 'FixedNumberGenerator', []),
            'Never reached.',
        ]);

        expect(fn(): mixed => (new ThrowingToolAgent())->prompt('Generate'))
            ->toThrow(RuntimeException::class, 'Listener failed.');

        expect(toolFailureLogs())->toBeEmpty();
    } finally {
        EventManager::instance()->off('Ai.invokingTool', $listener);
    }
});

test('a tool failed listener that rethrows fails the run with the tool exception', function (): void {
    $listener = function (ToolFailed $event): void {
        throw $event->exception;
    };

    EventManager::instance()->on('Ai.toolFailed', $listener);

    try {
        ThrowingToolAgent::fake([
            new ToolCall('call-1', 'FixedNumberGenerator', []),
            'Never reached.',
        ]);

        expect(fn(): mixed => (new ThrowingToolAgent())->prompt('Generate'))
            ->toThrow(fn(Exception $exception): bool => !$exception instanceof ToolExecutionException
                && $exception->getMessage() === 'Forced to throw exception.');

        expect(toolFailureLogs())->toBeEmpty();
    } finally {
        EventManager::instance()->off('Ai.toolFailed', $listener);
    }
});
