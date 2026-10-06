<?php
declare(strict_types=1);

use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Step;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;

test('the replay state never reaches a serialized event', function (): void {
    $event = new ToolApprovalRequest('event-id', collection([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, collection([pausedStep()]));

    expect($event->toArray())->not->toHaveKey('steps')
        ->and($event->toArray()['type'])->toBe('tool_approval_request');
});

function pausedStep(): Step
{
    return new Step('', [], [], FinishReason::ToolCalls, new TextUsage(), new Meta(), '', [['type' => 'thinking', 'signature' => 'sig-1']]);
}
