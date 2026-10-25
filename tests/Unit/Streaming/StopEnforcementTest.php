<?php
declare(strict_types=1);

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Exception\StoppedException;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\TextDelta;
use JMac\Testing\Double;

test('a stopped stream marks the response and reports to catch, not then', function (): void {
    $response = new StreamableAgentResponse('invocation-1', function (): Generator {
        yield new TextDelta('event-1', 'message-1', 'Hello', time());

        throw new StoppedException('The stream was stopped.');
    }, new Meta('fake', 'model'));

    $caught = [];
    $completed = 0;

    $response->catch(function (Throwable $exception) use (&$caught): void {
        $caught[] = $exception;
    });

    $response->then(function () use (&$completed): void {
        $completed++;
    });

    $events = [];

    try {
        foreach ($response as $event) {
            $events[] = $event;
        }

        expect(true)->toBeFalse('The stream must throw a StoppedException.');
    } catch (StoppedException) {
    }

    expect($response->wasStopped())->toBeTrue()
        ->and($response->hasYielded())->toBeTrue()
        ->and($events)->toHaveCount(1)
        ->and($caught)->toHaveCount(1)
        ->and($caught[0])->toBeInstanceOf(StoppedException::class)
        ->and($completed)->toBe(0);
});

test('a completed stream is never reported as stopped', function (): void {
    $response = new StreamableAgentResponse('invocation-1', fn(): Generator => yield from [
        new TextDelta('event-1', 'message-1', 'Hello', time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ], new Meta('fake', 'model'));

    iterator_to_array($response);

    expect($response->wasStopped())->toBeFalse();
});

test('a prompt carries its stop predicate through revise and tool swaps', function (): void {
    $agent = Double::for(Agent::class);
    $provider = Double::for(TextProvider::class);

    $stopCheck = fn(): bool => false;

    $prompt = new AgentPrompt($agent, 'Hello', [], $provider, 'model', stopCheck: $stopCheck);

    expect($prompt->stopCheck)->toBe($stopCheck)
        ->and($prompt->revise('Revised')->stopCheck)->toBe($stopCheck)
        ->and($prompt->withTools([])->stopCheck)->toBe($stopCheck)
        ->and($prompt->withStopCheck(null)->stopCheck)->toBeNull();
});
