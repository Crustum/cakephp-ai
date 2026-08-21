<?php
declare(strict_types=1);

use Crustum\Ai\Approvals\Decision;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Job\BroadcastAgentJob;
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
    AssistantAgent::fake(['Hello world']);

    $received = null;

    $job = new BroadcastAgentJob(
        agent: new AssistantAgent(),
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received): void {
        $received = $response;
    });

    $job->handle();

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->text)->toBe('Hello world');
});

test('multiple then callbacks all receive streamed agent response', function (): void {
    AssistantAgent::fake(['Hello world']);

    $receivedA = null;
    $receivedB = null;

    $job = new BroadcastAgentJob(
        agent: new AssistantAgent(),
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$receivedA): void {
        $receivedA = $response;
    });

    $job->then(function ($response) use (&$receivedB): void {
        $receivedB = $response;
    });

    $job->handle();

    expect($receivedA)->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($receivedB)->toBeInstanceOf(StreamedAgentResponse::class);
});

test('a resume streams the decision map instead of the prompt', function (): void {
    ConversationalAgent::fake();

    $job = new BroadcastAgentJob(
        agent: new ConversationalAgent(),
        prompt: Decisions::from(['call-1' => Decision::approve()]),
        channels: new Channel('test-channel'),
    );

    $job->handle();

    ConversationalAgent::assertPrompted(fn($prompt): bool => $prompt->approvalDecisions?->get('call-1')?->isApproved() === true);
});

test('failed broadcasts a stream_failed event with recoverable false on the configured channel', function (): void {
    $channel = new Channel('test-channel');

    $job = new BroadcastAgentJob(
        agent: new AssistantAgent(),
        prompt: 'Say hello',
        channels: $channel,
    );

    $invocationId = $job->invocationId;
    $job = unserialize(serialize($job));

    $job->failed(new RuntimeException('Something went wrong'));

    $broadcasts = TestBroadcaster::getBroadcastsByEvent('stream_failed');

    expect($broadcasts)->toHaveCount(1)
        ->and($broadcasts[0]['channels'])->toBe(['test-channel'])
        ->and($broadcasts[0]['payload']['invocation_id'])->toBe($invocationId)
        ->and($broadcasts[0]['payload']['recoverable'])->toBeFalse()
        ->and($broadcasts[0]['payload']['message'])->toBe('The stream failed.');
});

test('failed broadcasts on every channel when given an array', function (): void {
    $channels = [new Channel('a'), new Channel('b')];

    $job = new BroadcastAgentJob(
        agent: new AssistantAgent(),
        prompt: 'Say hello',
        channels: $channels,
    );

    $job->failed(new RuntimeException('boom'));

    $broadcasts = TestBroadcaster::getBroadcastsByEvent('stream_failed');

    expect($broadcasts)->toHaveCount(1)
        ->and($broadcasts[0]['channels'])->toBe(['a', 'b']);
});

test('failed event shares the invocation id with broadcasts from handle', function (): void {
    AssistantAgent::fake(['Hello world']);

    $job = new BroadcastAgentJob(
        agent: new AssistantAgent(),
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->handle();

    $invocationId = $job->invocationId;

    $job->failed(new RuntimeException('boom'));

    $broadcastIds = array_values(array_unique(array_map(
        fn(array $broadcast): mixed => $broadcast['payload']['invocation_id'] ?? null,
        TestBroadcaster::getBroadcasts(),
    )));

    expect($broadcastIds)->toBe([$invocationId]);
});

test('an oversized broadcast frame does not abort the stream and then still resolves', function (): void {
    Broadcasting::setConfig('default', [
        'className' => ThrowingBroadcaster::class,
        'connectionName' => 'default',
    ]);
    Broadcasting::getRegistry()->reset();

    AssistantAgent::fake(['Hello world']);

    $received = null;

    $job = new BroadcastAgentJob(
        agent: new AssistantAgent(),
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received): void {
        $received = $response;
    });

    $job->handle();

    expect($received)->not->toBeNull('then() callback was never invoked despite a failed broadcast')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->text)->toBe('Hello world');
});

test('streamed response passed to then is fully resolved', function (): void {
    AssistantAgent::fake(['Hello world']);

    $received = null;

    $job = new BroadcastAgentJob(
        agent: new AssistantAgent(),
        prompt: 'Say hello',
        channels: new Channel('test-channel'),
    );

    $job->then(function ($response) use (&$received): void {
        $received = $response;
    });

    $job->handle();

    expect($received)->not->toBeNull('then() callback was never invoked')
        ->toBeInstanceOf(StreamedAgentResponse::class)
        ->and($received->events)->not->toBeEmpty()
        ->and($received->text)->toBe('Hello world');
});
