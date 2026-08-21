<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Approvals\ApprovalMismatchException;
use Crustum\Ai\Approvals\Decision;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Event\ToolApprovalRequested;
use Crustum\Ai\Event\ToolApprovalResolved;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Prompts\QueuedAgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\StructuredAgentResponse;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Test\Fixtures\Agents\ConversationalAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingApprovableAgent;
use Crustum\Ai\Test\Fixtures\Tools\ApprovableNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Tools\Request as ToolRequest;
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

test('decision maps normalize booleans, blank reasons, and blanket helpers', function (): void {
    $approval = Decision::normalize([
        'call-1' => true,
        'call-2' => false,
        'call-3' => Decision::edit(['path' => '/tmp/file']),
    ]);

    $approveAll = Decision::approveAll();
    $rejectAll = Decision::rejectAll();

    expect($approval['call-1']->isApproved())->toBeTrue()
        ->and($approval['call-2']->isRejected())->toBeTrue()
        ->and($approval['call-3']->isEdited())->toBeTrue()
        ->and($approval['call-3']->arguments)->toBe(['path' => '/tmp/file'])
        ->and(Decision::reject('')->result)->toBeNull()
        ->and(Decision::reject('   ')->result)->toBeNull()
        ->and(Decision::reject('Already handled')->result)->toBe('Already handled')
        ->and($approveAll->get('*')->isApproved())->toBeTrue()
        ->and($rejectAll->get('*')->isRejected())->toBeTrue()
        ->and($rejectAll->get('*')->result)->toBeNull();
});

test('decision maps may approve or reject every remaining tool call', function (): void {
    $approved = Decisions::from(['call-1' => false])->approveRemaining();
    $rejected = Decisions::from(['call-1' => true])->rejectRemaining('Not now');

    expect($approved->get('call-1')->isRejected())->toBeTrue()
        ->and($approved->get('*')->isApproved())->toBeTrue()
        ->and($rejected->get('call-1')->isApproved())->toBeTrue()
        ->and($rejected->get('*')->isRejected())->toBeTrue()
        ->and($rejected->get('*')->result)->toBe('Not now');
});

test('invalid decision maps are rejected', function (): void {
    expect(fn(): array => Decision::normalize(['*' => Decision::edit(['path' => '/tmp/file'])]))
        ->toThrow(InvalidArgumentException::class, 'The wildcard decision may not use the edit action.')
        ->and(fn(): array => Decision::normalize(['call-1' => 'approve']))
        ->toThrow(InvalidArgumentException::class, 'Tool approval decisions must be Decision instances or booleans.')
        ->and(fn(): array => Decision::normalize(['call-1' => ['call-1' => false]]))
        ->toThrow(InvalidArgumentException::class, 'Tool approval decisions must be Decision instances or booleans.')
        ->and(fn(): Decisions => Decisions::from([]))
        ->toThrow(InvalidArgumentException::class, 'Tool approval decisions may not be empty.');
});

test('a paused response exposes everything a controller needs to build its own approval envelope', function (): void {
    $pending = new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file');

    $paused = AgentResponse::fakeWithPendingApprovals([$pending])->withinConversation('conversation-1');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->conversationId)->toBe('conversation-1')
        ->and($paused->pendingApprovals->map(fn(PendingApproval $approval): array => $approval->toArray())->toList())
        ->toBe([$pending->toArray()]);
});

test('non-paused responses render without the approval envelope', function (): void {
    $complete = (new AgentResponse('invocation-1', 'Done', new Usage(), new Meta()))->withinConversation('conversation-1');
    $structured = new StructuredAgentResponse('invocation-1', ['number' => 72019], '72019', new Usage(), new Meta());

    expect($complete->hasPendingApprovals())->toBeFalse()
        ->and($complete->conversationId)->toBe('conversation-1')
        ->and($complete->text)->toBe('Done')
        ->and((string)$complete)->toBe('Done')
        ->and($structured->toJson())->toBe(json_encode(['number' => 72019]))
        ->and($structured->toArray())->toBe(['number' => 72019]);
});

test('approval overrides take precedence over the tool default', function (): void {
    $tool = new ApprovableNumberGenerator();

    expect($tool->shouldRequestApproval(new ToolRequest([])))->not->toBeNull()
        ->and($tool->withoutApproval()->shouldRequestApproval(new ToolRequest([])))->toBeNull()
        ->and($tool->requireApproval('Dangerous')->shouldRequestApproval(new ToolRequest([])))->not->toBeNull()
        ->and($tool->requireApproval('Dangerous')->shouldRequestApproval(new ToolRequest([]))->reason)->toBe('Dangerous');
});

test('a paused stream dispatches the tool approval requested event', function (): void {
    ConversationalAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
        ]),
    ]);

    $response = (new ConversationalAgent())->stream('Delete config/app.php');
    $response->each(fn(): true => true);

    $this->assertAiEventDispatched(
        ToolApprovalRequested::class,
        fn(ToolApprovalRequested $event): bool => $event->pendingApprovals->count() === 1
            && $event->pendingApprovals->first()->id === 'call-1',
    );
    $this->assertPendingApprovals(1);
    $this->assertPendingApproval(
        'DeleteFile',
        fn(PendingApproval $approval): bool => $approval->id === 'call-1',
    );
})->skip('Unsupported on Cake 4');

test('approval resumes flow through queue and broadcast delivery styles', function (): void {
    ConversationalAgent::fake();

    (new ConversationalAgent())->queue(Decisions::from(['call-1' => true]));

    ConversationalAgent::assertQueued(
        fn(QueuedAgentPrompt $prompt): bool => $prompt->approvalDecisions?->get('call-1')?->isApproved() === true,
    );

    (new ConversationalAgent())
        ->broadcastNow(Decisions::from(['call-1' => true]), new Channel('approvals'))
        ->each(fn(): true => true);

    ConversationalAgent::assertPrompted(
        fn(AgentPrompt $prompt): bool => $prompt->approvalDecisions?->get('call-1')?->isApproved() === true,
    );
    $this->assertApprovalDecision('call-1', true);

    (new ConversationalAgent())->broadcastOnQueue(
        Decisions::from(['call-1' => true]),
        new Channel('approvals'),
    );

    ConversationalAgent::assertQueued(
        fn(QueuedAgentPrompt $prompt): bool => $prompt->approvalDecisions?->get('call-1')?->isApproved() === true,
    );

    (new ConversationalAgent())->queue(Decision::approveAll());

    ConversationalAgent::assertQueued(
        fn(QueuedAgentPrompt $prompt): bool => $prompt->approvalDecisions?->get('*')?->isApproved() === true,
    );
})->skip('Unsupported on Cake 4');

function toolApprovalToolUse(string $id): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'msg_tool_' . $id,
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [[
            'type' => 'tool_use',
            'id' => $id,
            'name' => 'ApprovableNumberGenerator',
            'input' => (object)[],
        ]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function toolApprovalMixedToolUses(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'msg_tool_mixed',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ApprovableNumberGenerator', 'input' => (object)[]],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'ApprovableNumberGenerator', 'input' => (object)[]],
        ],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function toolApprovalText(string $text): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'msg_' . uniqid(),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function toolApprovalConversationStore(): void
{
    Configure::write('Ai.conversations.generate_title', false);
    Configure::write('Ai.conversations.connection', 'test');
    Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
    Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');

    Ai::manager()->setConversationStore(new DatabaseConversationStore());
}

test('an approval mismatch renders as a 409 carrying the pending approvals', function (): void {
    $exception = new ApprovalMismatchException('Approval decisions do not match the pending tool calls.', collection([
        new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a tracked project file'),
    ]));

    $response = $exception->render();

    expect($response->getStatusCode())->toBe(409)
        ->and(json_decode($response->getBody()->getContents(), true))->toBe([
            'message' => 'Approval decisions do not match the pending tool calls.',
            'approvals' => [[
                'id' => 'call-1',
                'tool' => 'DeleteFile',
                'arguments' => ['path' => 'config/app.php'],
                'reason' => 'Deletes a tracked project file',
            ]],
        ]);
});

test('a paused prompt dispatches the tool approval requested event with its conversation', function (): void {
    toolApprovalConversationStore();

    aiHttpFake(['api.anthropic.com/*' => toolApprovalToolUse('toolu_1')]);

    $paused = (new RememberingApprovableAgent())
        ->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])
        ->prompt('Generate a number', provider: 'anthropic');

    $this->assertAiEventDispatched(ToolApprovalRequested::class, fn(ToolApprovalRequested $event): bool => $event->pendingApprovals->count() === 1
        && $event->pendingApprovals->first()->id === 'toolu_1'
        && $event->conversationId === $paused->conversationId
        && $event->conversationId !== null);
});

test('a run that only pauses does not dispatch the tool approval resolved event', function (): void {
    toolApprovalConversationStore();

    aiHttpFake(['api.anthropic.com/*' => toolApprovalToolUse('toolu_1')]);

    (new RememberingApprovableAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])->prompt('Generate a number', provider: 'anthropic');

    $this->assertAiEventNotDispatched(ToolApprovalResolved::class);
});

test('a mixed resume dispatches the tool approval resolved event with approved and denied results', function (): void {
    toolApprovalConversationStore();

    aiHttpFake(['api.anthropic.com/*' => toolApprovalMixedToolUses()]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true, 'toolu_2' => false]), provider: 'anthropic');

    $this->assertAiEventDispatched(ToolApprovalResolved::class, function (ToolApprovalResolved $event): bool {
        $results = $event->toolResults->toList();

        return count($results) === 2
            && $results[0]->id === 'toolu_1'
            && $results[0]->denied === false
            && $results[1]->id === 'toolu_2'
            && $results[1]->denied === true
            && $event->conversationId !== null;
    });
});

test('an approved streamed resume dispatches the tool approval resolved event', function (): void {
    toolApprovalConversationStore();

    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            toolApprovalToolUse('toolu_1'),
            toolApprovalText('The number is 72019.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    $response = (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->stream(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    $response->each(fn(): true => true);

    $this->assertAiEventDispatched(ToolApprovalResolved::class, function (ToolApprovalResolved $event): bool {
        $results = $event->toolResults->toList();

        return count($results) === 1
            && $results[0]->id === 'toolu_1'
            && $results[0]->denied === false;
    });
});
