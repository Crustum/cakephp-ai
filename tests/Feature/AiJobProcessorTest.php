<?php
declare(strict_types=1);

use Cake\Queue\Job\Message;
use Crustum\Ai\Job\PendingDispatch;
use Crustum\Ai\Queue\AiJobProcessor;
use Crustum\Ai\Test\Fixtures\Jobs\FailingJob;
use Crustum\Ai\Test\Fixtures\Jobs\PlainJob;
use Crustum\Ai\Test\Fixtures\Jobs\ResponseReturningJob;
use Crustum\Ai\Test\Fixtures\Jobs\SucceedingJob;
use Crustum\Ai\Test\Fixtures\Jobs\UnbuildableJob;
use Enqueue\Null\NullContext;
use Enqueue\Null\NullMessage;
use Interop\Queue\Processor;
use Psr\Log\NullLogger;

function aiProcessorMessage(string $jobClass, array $data): Message
{
    $body = [
        'class' => [$jobClass, 'execute'],
        'args' => [$data],
        'data' => $data,
    ];

    return new Message(
        new NullMessage(json_encode($body, JSON_THROW_ON_ERROR)),
        new NullContext(),
    );
}

function aiTestProcessor(): AiJobProcessor
{
    return new AiJobProcessor(new NullLogger());
}

beforeEach(function (): void {
    FailingJob::reset();
});

test('successful ai job fires then callbacks with the response', function (): void {
    aiSyncQueue();

    $GLOBALS['processorReceived'] = null;

    $dispatch = new PendingDispatch(SucceedingJob::class, ['value' => 'job-response']);
    $dispatch->getJob()->then(function ($response): void {
        $GLOBALS['processorReceived'] = $response;
    });
    unset($dispatch);

    expect($GLOBALS['processorReceived'])->toBe('job-response');
});

test('failing ai job invokes the failed hook and catch callbacks, then requeues', function (): void {
    $processor = aiTestProcessor();
    $result = $processor->process(
        aiProcessorMessage(FailingJob::class, ['marker' => 'abc'])->getOriginalMessage(),
        new NullContext(),
    );

    expect(FailingJob::$failedCalls)->toHaveCount(1)
        ->and(FailingJob::$failedCalls[0]['exception'])->toBeInstanceOf(RuntimeException::class)
        ->and(FailingJob::$failedCalls[0]['data']['marker'])->toBe('abc')
        ->and($result->getStatus())->toBe(Processor::REQUEUE);

    aiSyncQueue();

    $GLOBALS['processorCaught'] = null;

    $dispatch = new PendingDispatch(FailingJob::class, []);
    $dispatch->getJob()->catch(function ($exception): void {
        $GLOBALS['processorCaught'] = $exception;
    });
    unset($dispatch);

    expect($GLOBALS['processorCaught'])->toBeInstanceOf(RuntimeException::class);
});

test('message with an invalid target is rejected, not fatal', function (): void {
    $processor = aiTestProcessor();

    $result = $processor->process(
        new NullMessage(json_encode(['nope' => true], JSON_THROW_ON_ERROR)),
        new NullContext(),
    );

    expect($result)->toBe(Processor::REJECT);
});

test('unbuildable ai job is rejected instead of killing the worker', function (): void {
    $processor = aiTestProcessor();

    $result = $processor->process(
        aiProcessorMessage(UnbuildableJob::class, [])->getOriginalMessage(),
        new NullContext(),
    );

    expect($result)->toBe(Processor::REJECT)
        ->and(FailingJob::$failedCalls)->toBeEmpty();
});

test('non-ai jobs run through the same generic lifecycle', function (): void {
    $processor = aiTestProcessor();

    $result = $processor->process(
        aiProcessorMessage(PlainJob::class, [])->getOriginalMessage(),
        new NullContext(),
    );

    expect($result)->toBe(Processor::ACK);
});

test('a throwing then callback does not alter the job result', function (): void {
    aiSyncQueue();

    $GLOBALS['processorSecondRan'] = false;

    $dispatch = new PendingDispatch(SucceedingJob::class, ['value' => 'job-response']);
    $dispatch->getJob()->then(function (): void {
        throw new RuntimeException('observer boom');
    });
    $dispatch->getJob()->then(function (): void {
        $GLOBALS['processorSecondRan'] = true;
    });
    unset($dispatch);

    expect($GLOBALS['processorSecondRan'])->toBeTrue();
});

test('ai jobs dispatch seen before terminal events like the base processor', function (): void {
    $processor = aiTestProcessor();
    $fired = [];
    foreach (['Processor.message.seen', 'Processor.message.success', 'Processor.message.invalid'] as $name) {
        $processor->getEventManager()->on($name, function () use (&$fired, $name): void {
            $fired[] = $name;
        });
    }

    $result = $processor->process(
        aiProcessorMessage(SucceedingJob::class, ['value' => 'x'])->getOriginalMessage(),
        new NullContext(),
    );

    expect($result)->toBe(Processor::ACK)
        ->and($fired)->toBe(['Processor.message.seen', 'Processor.message.success']);
});

test('a job returning null succeeds with a success event, not a requeue', function (): void {
    $processor = aiTestProcessor();
    $fired = [];
    foreach (['Processor.message.seen', 'Processor.message.success', 'Processor.message.failure'] as $name) {
        $processor->getEventManager()->on($name, function () use (&$fired, $name): void {
            $fired[] = $name;
        });
    }

    $result = $processor->process(
        aiProcessorMessage(ResponseReturningJob::class, ['value' => 'streamed'])->getOriginalMessage(),
        new NullContext(),
    );

    expect($result)->toBe(Processor::ACK)
        ->and($fired)->toBe(['Processor.message.seen', 'Processor.message.success']);
});

test('non-ai jobs run through the same generic lifecycle with exactly one seen event', function (): void {
    $processor = aiTestProcessor();
    $seen = 0;
    $processor->getEventManager()->on('Processor.message.seen', function () use (&$seen): void {
        $seen++;
    });

    $result = $processor->process(
        aiProcessorMessage(PlainJob::class, [])->getOriginalMessage(),
        new NullContext(),
    );

    expect($result)->toBe(Processor::ACK)->and($seen)->toBe(1);
});
