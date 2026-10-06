<?php
declare(strict_types=1);

use Cake\Collection\Collection;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Step;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;

function streamedResponseFor(array $events): StreamedAgentResponse
{
    return new StreamedAgentResponse('invocation-id', new Collection($events), new Meta());
}

function streamedStep(string $text): Step
{
    return new Step($text, [], [], FinishReason::Stop, new TextUsage(), new Meta(), 'I thought about it.', [['type' => 'thinking', 'signature' => 'sig-1']]);
}

test('a paused stream exposes the steps carried by the approval request', function (): void {
    $response = streamedResponseFor([
        new ToolApprovalRequest('e1', collection([new PendingApproval('call-1', 'DeleteFile', [], 'Deletes a file')]), 1, collection([streamedStep('')])),
    ]);

    expect($response->steps->first()->replayBlocks)->toBe([['type' => 'thinking', 'signature' => 'sig-1']]);
});

test('a completed stream exposes the steps carried by the stream end', function (): void {
    $response = streamedResponseFor([new StreamEnd('e1', 'stop', new TextUsage(), 1, collection([streamedStep('Done.')]))]);

    expect($response->steps)->toHaveCount(1)
        ->and($response->steps->first()->text)->toBe('Done.')
        ->and($response->steps->first()->reasoning)->toBe('I thought about it.');
});

test('a stream carrying neither event exposes no steps', function (): void {
    expect(streamedResponseFor([])->steps)->toBeEmpty();
});
