<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Exception\ApprovalNotResumableException;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\Ai\Test\Fixtures\Agents\RememberingApprovableAgent;
use Crustum\Ai\Test\Fixtures\Agents\StatelessApprovableAgent;
use Crustum\Ai\Test\Fixtures\Agents\StatelessMixedToolsAgent;
use Crustum\Ai\Test\Fixtures\Tools\ApprovableNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\SideEffectRecorder;
use Crustum\Ai\Vercel\Vercel;

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

test('a gated tool pauses instead of throwing when the client replays history, even on the first turn', function (): void {
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

    $paused = (new StatelessApprovableAgent())
        ->withMessages([])
        ->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals->first()->id)->toBe('toolu_1');
});

test('a stateless pause resumes from client-replayed history when approved', function (): void {
    ApprovableNumberGenerator::$invocations = 0;

    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse([
            'id' => 'msg_2',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $chat = Vercel::chat([
        ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Generate a number']]],
        ['id' => 'm2', 'role' => 'assistant', 'parts' => [
            ['type' => 'tool-ApprovableNumberGenerator', 'toolCallId' => 'toolu_1', 'state' => 'approval-responded', 'input' => [], 'approval' => ['id' => 'toolu_1', 'approved' => true]],
        ]],
    ]);

    $response = (new StatelessApprovableAgent())
        ->withMessages($chat->history())
        ->prompt($chat, provider: 'anthropic');

    expect(ApprovableNumberGenerator::$invocations)->toBe(1)
        ->and($response->text)->toBe('The number is 72019.');
});

test('a gated tool on a conversational agent pauses ownerless instead of throwing', function (): void {
    Configure::write('Ai.conversations.generate_title', false);
    Ai::manager()->setConversationStore(new DatabaseConversationStore());

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
        ->and($paused->conversationId)->not->toBeNull();
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

    expect(fn(): mixed => (new StatelessMixedToolsAgent())->prompt('Record and generate', provider: 'anthropic'))
        ->toThrow(ApprovalNotResumableException::class)
        ->and(SideEffectRecorder::$invocations)->toBe(1);
});
