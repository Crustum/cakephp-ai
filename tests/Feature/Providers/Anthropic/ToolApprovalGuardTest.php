<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Exception\ApprovalNotResumableException;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Test\Fixtures\Agents\RememberingApprovableAgent;
use Crustum\Ai\Test\Fixtures\Agents\StatelessApprovableAgent;
use Crustum\Ai\Test\Fixtures\Agents\StatelessMixedToolsAgent;
use Crustum\Ai\Test\Fixtures\ConversationStores\InMemoryConversationStore;
use Crustum\Ai\Test\Fixtures\Tools\SideEffectRecorder;

beforeEach(function (): void {
    Configure::write('Ai.conversations.generate_title', false);
    Ai::manager()->setConversationStore(new InMemoryConversationStore());
});

test('a gated tool on a non-conversational agent throws when it pauses', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse([
            'id' => 'msg_tool_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'ApprovableNumberGenerator',
                'input' => (object)[],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    (new StatelessApprovableAgent())->prompt('Generate a number', provider: 'anthropic');
})->throws(ApprovalNotResumableException::class);

test('a gated tool on a non-conversational agent throws before streaming a pause to the client', function (): void {
    StatelessApprovableAgent::fake([
        new ToolCall('toolu_1', 'ApprovableNumberGenerator', [], 'result-1'),
    ]);

    $stream = (new StatelessApprovableAgent())->stream('Generate a number');

    $events = [];
    $thrown = null;

    try {
        foreach ($stream as $event) {
            $events[] = $event;
        }
    } catch (ApprovalNotResumableException $approvalNotResumableException) {
        $thrown = $approvalNotResumableException;
    }

    expect($thrown)->toBeInstanceOf(ApprovalNotResumableException::class)
        ->and(array_filter($events, fn($event): bool => $event instanceof ToolApprovalRequest))->toBeEmpty();
});

test('a gated tool on a conversational agent pauses ownerless instead of throwing', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse([
            'id' => 'msg_tool_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'ApprovableNumberGenerator',
                'input' => (object)[],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $paused = (new RememberingApprovableAgent())->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->conversationId)->not->toBeNull()
        ->and($paused->conversationUser)->toBeNull();
});

test('a non-resumable pause runs its step, then throws', function (): void {
    SideEffectRecorder::$invocations = 0;

    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse([
            'id' => 'msg_tool_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_side',
                    'name' => 'SideEffectRecorder',
                    'input' => (object)[],
                ],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_gated',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object)[],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    // The pause is rejected because the agent cannot resume; its ungated companion runs first, as any step's would.
    expect(fn(): AgentResponse => (new StatelessMixedToolsAgent())->prompt('Record and generate', provider: 'anthropic'))
        ->toThrow(ApprovalNotResumableException::class)
        ->and(SideEffectRecorder::$invocations)->toBe(1);
});
