<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Queue\QueueManager;
use Cake\Queue\TestSuite\TestQueueClient;
use Crustum\Ai\Job\PendingDispatch;
use Crustum\Ai\Queue\AiQueue;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Jobs\SucceedingJob;

test('queue names follow plugin config, ai by default', function (): void {
    expect(AiQueue::connection())->toBe('ai')
        ->and(AiQueue::queue())->toBe('ai');

    $connection = Configure::read('Ai.queue.connection');
    $queue = Configure::read('Ai.queue.queue');

    Configure::write('Ai.queue.connection', 'ai-custom');
    Configure::write('Ai.queue.queue', 'q-custom');

    try {
        expect(AiQueue::connection())->toBe('ai-custom')
            ->and(AiQueue::queue())->toBe('q-custom')
            ->and(AiQueue::defaultOptions())->toBe([
                'queue' => 'q-custom',
                'config' => 'ai-custom',
            ]);
    } finally {
        Configure::write('Ai.queue.connection', $connection);
        Configure::write('Ai.queue.queue', $queue);
    }
});

test('dispatching to the configured ai queue is accepted', function (): void {
    $dispatch = new PendingDispatch(SucceedingJob::class, ['value' => 'x']);

    expect($dispatch)->toBeInstanceOf(PendingDispatch::class);

    SucceedingJob::push(['value' => 'x']);

    expect(true)->toBeTrue();
});

test('dispatching to the default queue is refused', function (): void {
    expect(fn(): PendingDispatch => new PendingDispatch(
        SucceedingJob::class,
        [],
        ['config' => 'default'],
    ))->toThrow(InvalidArgumentException::class, 'must not use the `default` queue');

    expect(fn(): array => SucceedingJob::push([], ['config' => 'default']))->toThrow(
        InvalidArgumentException::class,
        'must not use the `default` queue',
    );
});

test('dispatching to a missing queue connection is refused with guidance', function (): void {
    expect(fn(): PendingDispatch => new PendingDispatch(
        SucceedingJob::class,
        [],
        ['config' => 'ai-missing'],
    ))->toThrow(InvalidArgumentException::class, 'dedicated `ai-missing` queue');
});

test('dispatching to a queue without the ai processor is refused', function (): void {
    QueueManager::setConfig('ai-plain', ['url' => 'null:']);

    expect(fn(): PendingDispatch => new PendingDispatch(
        SucceedingJob::class,
        [],
        ['config' => 'ai-plain'],
    ))->toThrow(InvalidArgumentException::class, 'must set `processor`');
});

test('ai jobs route to the ai queue, never default', function (): void {
    aiFakeQueue();
    AssistantAgent::fake(['Hello']);

    (new AssistantAgent())->queue('Hello');

    expect(TestQueueClient::getQueuedJobsByQueue('ai'))->not->toBeEmpty()
        ->and(TestQueueClient::getQueuedJobsByQueue('default'))->toBeEmpty()
        ->and(TestQueueClient::getQueuedJobsByConfig('ai'))->not->toBeEmpty()
        ->and(TestQueueClient::getQueuedJobsByConfig('default'))->toBeEmpty();
});
