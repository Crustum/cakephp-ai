<?php
declare(strict_types=1);

use Cake\Queue\Job\Message;
use Crustum\Ai\Job\InvokeAgentJob;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Enqueue\Null\NullContext;
use Enqueue\Null\NullMessage;
use Interop\Queue\Processor;

test('invoke agent job runs packed payload through prompt', function (): void {
    AssistantAgent::fake(['CakePHP']);

    $payload = InvokeAgentJob::payload(
        new AssistantAgent(),
        'What framework?',
    );
    $payload = json_decode(json_encode($payload), true);

    $job = new InvokeAgentJob();
    $response = $job->run($payload);

    expect($response)->toBeInstanceOf(AgentResponse::class)
        ->and($response->text)->toBe('CakePHP');

    AssistantAgent::assertPrompted('What framework?');
});

test('invoke agent job executes from a queue message and stores the response', function (): void {
    AssistantAgent::fake(['CakePHP']);

    $payload = InvokeAgentJob::payload(
        new AssistantAgent(),
        'What framework?',
    );
    $payload = json_decode(json_encode($payload), true);

    $body = [
        'class' => [InvokeAgentJob::class, 'execute'],
        'args' => [$payload],
        'data' => $payload,
    ];

    $job = new InvokeAgentJob();
    $result = $job->execute(new Message(
        new NullMessage(json_encode($body, JSON_THROW_ON_ERROR)),
        new NullContext(),
    ));

    expect($result)->toBe(Processor::ACK)
        ->and($job->getResponse())->toBeInstanceOf(AgentResponse::class);
});
