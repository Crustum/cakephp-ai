<?php
declare(strict_types=1);

use Crustum\Ai\Job\InvokeAgentJob;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Interop\Queue\Processor;

test('invoke agent job runs packed payload through prompt', function (): void {
    AssistantAgent::fake(['CakePHP']);

    $payload = InvokeAgentJob::payload(
        new AssistantAgent(),
        'What framework?',
    );
    $payload = json_decode(json_encode($payload), true);

    expect((new InvokeAgentJob())->run($payload))->toBe(Processor::ACK);

    AssistantAgent::assertPrompted('What framework?');
});
