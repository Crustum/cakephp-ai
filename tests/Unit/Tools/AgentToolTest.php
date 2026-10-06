<?php
declare(strict_types=1);

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Tools\AgentTool;
use Crustum\Ai\Tools\Request;
use JMac\Testing\Double;

test('an agent tool streams its events by default and returns its final text', function (): void {
    $stream = new StreamableAgentResponse('invocation-sub', fn(): Generator => yield from [
        new TextDelta('event-1', 'message-1', 'sub answer', time()),
        new StreamEnd('event-2', 'stop', new TextUsage(), time()),
    ], new Meta('fake', 'model'));

    $agent = Double::for(Agent::class);
    $agent->expects('stream')->returns($stream);
    $agent->expects('prompt')->never();

    $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

    $events = iterator_to_array($generator);

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(TextDelta::class)
        ->and($generator->getReturn())->toBe('sub answer');
});

test('a failing sub-agent surfaces its error as the tool result on the streaming path', function (): void {
    $agent = Double::for(Agent::class);
    $agent->expects('stream')->throws(new RuntimeException('provider exploded'));

    $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

    expect(iterator_to_array($generator))->toBe([])
        ->and($generator->getReturn())->toBe('Agent failed: provider exploded');
});
