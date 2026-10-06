<?php
declare(strict_types=1);

use Crustum\Ai\Approvals\Decision;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Job\BroadcastAgentJob;
use Crustum\Ai\Job\PendingDispatch;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ConversationalAgent;
use Crustum\Ai\Test\Fixtures\ThrowingBroadcaster;
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

test('then callback receives streamed agent response', function (): void {
    aiSyncQueue();
    AssistantAgent::fake(['Hello world']);

    $GLOBALS['broadcastReceived'] = null;

    $dispatch = new PendingDispatch(
        BroadcastAgentJob::class,
        BroadcastAgentJob::payload(
            new AssistantAgent(),
            'Say hello',
            new Channel('test-channel'),
        ),
    );

    $dispatch->getJob()->then(function ($response): void {
        $GLOBALS['broadcastReceived'] = $response;
    });

    unset($dispatch);

    $received = $GLOBALS['broadcastReceived'];

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->text)->toBe('Hello world');
});

test('multiple then callbacks all receive streamed agent response', function (): void {
    aiSyncQueue();
    AssistantAgent::fake(['Hello world']);

    $GLOBALS['broadcastReceivedA'] = null;
    $GLOBALS['broadcastReceivedB'] = null;

    $dispatch = new PendingDispatch(
        BroadcastAgentJob::class,
        BroadcastAgentJob::payload(
            new AssistantAgent(),
            'Say hello',
            new Channel('test-channel'),
        ),
    );

    $dispatch->getJob()->then(function ($response): void {
        $GLOBALS['broadcastReceivedA'] = $response;
    });

    $dispatch->getJob()->then(function ($response): void {
        $GLOBALS['broadcastReceivedB'] = $response;
    });

    unset($dispatch);

    expect($GLOBALS['broadcastReceivedA'])->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($GLOBALS['broadcastReceivedB'])->toBeInstanceOf(StreamedAgentResponse::class);
});

test('a resume streams the decision map instead of the prompt', function (): void {
    ConversationalAgent::fake();

    (new BroadcastAgentJob())->run(BroadcastAgentJob::payload(
        agent: new ConversationalAgent(),
        prompt: Decisions::from(['call-1' => Decision::approve()]),
        channels: new Channel('test-channel'),
    ));

    ConversationalAgent::assertPrompted(fn($prompt): bool => $prompt->approvalDecisions?->get('call-1')?->isApproved() === true);
});

test('failed broadcasts a stream_failed event with recoverable false on the configured channel', function (): void {
    $channel = new Channel('test-channel');

    $payload = BroadcastAgentJob::payload(
        agent: new AssistantAgent(),
        prompt: 'Say hello',
        channels: $channel,
    );

    (new BroadcastAgentJob())->failed(new RuntimeException('Something went wrong'), $payload);

    $broadcasts = TestBroadcaster::getBroadcastsByEvent('stream_failed');

    expect($broadcasts)->toHaveCount(1)
        ->and($broadcasts[0]['channels'])->toBe(['test-channel'])
        ->and($broadcasts[0]['payload']['invocation_id'])->toBe($payload['invocationId'])
        ->and($broadcasts[0]['payload']['recoverable'])->toBeFalse()
        ->and($broadcasts[0]['payload']['message'])->toBe('The stream failed.');
});

test('failed broadcasts on every channel when given an array', function (): void {
    $channels = [new Channel('a'), new Channel('b')];

    $payload = BroadcastAgentJob::payload(
        agent: new AssistantAgent(),
        prompt: 'Say hello',
        channels: $channels,
    );

    (new BroadcastAgentJob())->failed(new RuntimeException('boom'), $payload);

    $broadcasts = TestBroadcaster::getBroadcastsByEvent('stream_failed');

    expect($broadcasts)->toHaveCount(1)
        ->and($broadcasts[0]['channels'])->toBe(['a', 'b']);
});

test('failed event shares the invocation id with broadcasts from run', function (): void {
    AssistantAgent::fake(['Hello world']);

    $payload = BroadcastAgentJob::payload(
        agent: new AssistantAgent(),
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    (new BroadcastAgentJob())->run($payload);
    (new BroadcastAgentJob())->failed(new RuntimeException('boom'), $payload);

    $broadcastIds = array_values(array_unique(array_map(
        fn(array $broadcast): mixed => $broadcast['payload']['invocation_id'] ?? null,
        TestBroadcaster::getBroadcasts(),
    )));

    expect($broadcastIds)->toBe([$payload['invocationId']]);
});

test('a job failure broadcasts stream_failed and fires catch callbacks', function (): void {
    aiSyncQueue();
    AssistantAgent::fake(fn(): never => throw new RuntimeException('boom'));

    $GLOBALS['broadcastCaught'] = null;

    $dispatch = new PendingDispatch(
        BroadcastAgentJob::class,
        BroadcastAgentJob::payload(
            new AssistantAgent(),
            'Say hello',
            new Channel('test-channel'),
        ),
    );

    $dispatch->getJob()->catch(function ($exception): void {
        $GLOBALS['broadcastCaught'] = $exception;
    });

    unset($dispatch);

    $broadcasts = TestBroadcaster::getBroadcastsByEvent('stream_failed');

    expect($broadcasts)->toHaveCount(1)
        ->and($broadcasts[0]['payload']['message'])->toBe('The stream failed.')
        ->and($GLOBALS['broadcastCaught'])->toBeInstanceOf(RuntimeException::class);
});

test('an oversized broadcast frame does not abort the stream and then still resolves', function (): void {
    Broadcasting::setConfig('default', [
        'className' => ThrowingBroadcaster::class,
        'connectionName' => 'default',
    ]);
    Broadcasting::getRegistry()->reset();

    aiSyncQueue();
    AssistantAgent::fake(['Hello world']);

    $GLOBALS['broadcastReceived'] = null;

    $dispatch = new PendingDispatch(
        BroadcastAgentJob::class,
        BroadcastAgentJob::payload(
            new AssistantAgent(),
            'Say hello',
            new Channel('test-channel'),
        ),
    );

    $dispatch->getJob()->then(function ($response): void {
        $GLOBALS['broadcastReceived'] = $response;
    });

    unset($dispatch);

    $received = $GLOBALS['broadcastReceived'];

    expect($received)->not->toBeNull('then() callback was never invoked despite a failed broadcast')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->text)->toBe('Hello world');
});

test('streamed response passed to then is fully resolved', function (): void {
    aiSyncQueue();
    AssistantAgent::fake(['Hello world']);

    $GLOBALS['broadcastReceived'] = null;

    $dispatch = new PendingDispatch(
        BroadcastAgentJob::class,
        BroadcastAgentJob::payload(
            new AssistantAgent(),
            'Say hello',
            new Channel('test-channel'),
        ),
    );

    $dispatch->getJob()->then(function ($response): void {
        $GLOBALS['broadcastReceived'] = $response;
    });

    unset($dispatch);

    $received = $GLOBALS['broadcastReceived'];

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->events)->not->toBeEmpty()
        ->and($received->text)->toBe('Hello world');
});
