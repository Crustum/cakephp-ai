<?php
declare(strict_types=1);

use Crustum\Ai\Attributes\WithoutBroadcasting;
use Crustum\Ai\Job\BroadcastAgentJob;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolCall;
use Crustum\Ai\Streaming\Event\ToolResult;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\NonBroadcastingTextAgent;
use Crustum\Ai\Test\Fixtures\Agents\NonBroadcastingToolAgent;
use Crustum\Broadcasting\Broadcasting;
use Crustum\Broadcasting\Channel\Channel;
use Crustum\Broadcasting\TestSuite\TestBroadcaster;

beforeEach(function (): void {
    foreach (Broadcasting::configured() as $config) {
        Broadcasting::drop((string)$config);
    }

    Broadcasting::setConfig('default', [
        'className' => TestBroadcaster::class,
        'connectionName' => 'default',
    ]);

    Broadcasting::getRegistry()->reset();
    TestBroadcaster::clearBroadcasts();
});

test('it rejects event classes that are not stream events', function (): void {
    expect(fn(): WithoutBroadcasting => new WithoutBroadcasting('App\Events\Typo'))
        ->toThrow(InvalidArgumentException::class);
});

test('no events are withheld when the attribute is absent', function (): void {
    expect(WithoutBroadcasting::eventsFor(new AssistantAgent()))->toBe([]);
});

test('listed events are withheld when the attribute is present', function (): void {
    expect(WithoutBroadcasting::eventsFor(new NonBroadcastingToolAgent()))
        ->toContain(ToolResult::class)
        ->toContain(ToolCall::class);
});

test('events not listed in the attribute are not withheld', function (): void {
    expect(WithoutBroadcasting::eventsFor(new NonBroadcastingToolAgent()))
        ->not->toContain(TextDelta::class);
});

test('a null target withholds nothing', function (): void {
    expect(WithoutBroadcasting::eventsFor(null))->toBe([]);
});

test('broadcast withholds listed events from the channel but assembles the full response', function (): void {
    NonBroadcastingTextAgent::fake(['Hello world']);

    $received = null;

    (new NonBroadcastingTextAgent())
        ->broadcastNow('Say hello', new Channel('test-channel'))
        ->then(function ($response) use (&$received): void {
            $received = $response;
        });

    expect(TestBroadcaster::getBroadcastsByEvent('text_delta'))->toBeEmpty();
    expect(TestBroadcaster::getBroadcastsByEvent('stream_start'))->not->toBeEmpty();

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->and($received->text)->toBe('Hello world');
});

test('broadcast agent withholds listed events from the channel but resolves the full response', function (): void {
    NonBroadcastingTextAgent::fake(['Hello world']);

    (new BroadcastAgentJob())->run(BroadcastAgentJob::payload(
        agent: new NonBroadcastingTextAgent(),
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    ));

    expect(TestBroadcaster::getBroadcastsByEvent('text_delta'))->toBeEmpty();
    expect(TestBroadcaster::getBroadcastsByEvent('stream_start'))->not->toBeEmpty();
});
