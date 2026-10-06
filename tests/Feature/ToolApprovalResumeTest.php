<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Http\Client\Exception\ClientException;
use Crustum\Ai\Ai;
use Crustum\Ai\Approvals\ApprovalMismatchException;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Enums\MessageStatus;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Providers\AnthropicProvider;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Test\Fixtures\Agents\RememberingApprovableAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingMultiStepApprovableAgent;
use Crustum\Ai\Test\Fixtures\Agents\RememberingToolUsingAgent;
use Crustum\Ai\Test\Fixtures\Tools\ApprovableNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

function fakeAnthropicToolUse(string $id): AiHttpResponseDefinition
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

function fakeAnthropicText(string $text): AiHttpResponseDefinition
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

beforeEach(function (): void {
    Configure::write('Ai.conversations.generate_title', false);
    Configure::write('Ai.conversations.connection', 'test');
    Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
    Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');

    Ai::manager()->setConversationStore(new DatabaseConversationStore());
});

test('a remembered agent pauses for approval, persists the tool_use, and resumes from history when approved', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            fakeAnthropicToolUse('toolu_1'),
            fakeAnthropicText('The number is 72019.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals)->toHaveCount(1)
        ->and($paused->pendingApprovals->first()->id)->toBe('toolu_1')
        ->and($paused->conversationId)->not->toBeNull();

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $assistantRow = $messagesTable->find()
        ->where(['conversation_id' => $paused->conversationId, 'role' => 'assistant'])
        ->orderByDesc('id')
        ->first();

    expect($assistantRow->steps)->toHaveCount(1)
        ->and($assistantRow->steps[0]['tool_calls'])->toHaveCount(1)
        ->and($assistantRow->steps[0]['tool_calls'][0]['id'])->toBe('toolu_1')
        ->and($assistantRow->steps[0]['tool_calls'][0])->not->toHaveKey('result');

    $resumed = (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->hasPendingApprovals())->toBeFalse()
        ->and($resumed->text)->toBe('The number is 72019.')
        ->and($resumed->toolResults)->toHaveCount(1)
        ->and($resumed->toolResults->first()->result)->toBe('72019');
});

test('a resume settles the paused turn and runs the tool once whoever resumes it', function (?object $resumer): void {
    ApprovableNumberGenerator::$invocations = 0;

    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            fakeAnthropicToolUse('toolu_1'),
            fakeAnthropicText('The number is 72019.'),
        ]),
    ]);

    $paused = (new RememberingApprovableAgent())->forUser((object)['id' => '00000000-0000-0000-0000-000000000001'])->prompt('Generate a number', provider: 'anthropic');

    $resumed = (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $resumer)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->text)->toBe('The number is 72019.')
        ->and(ApprovableNumberGenerator::$invocations)->toBe(1)
        ->and((new DatabaseConversationStore())->pendingApprovalsFor($paused->conversationId))->toBe([]);
})->with([
    'another participant' => [fn(): object => (object)['id' => '00000000-0000-0000-0000-000000000002']],
    'no participant' => [null],
]);

test('an ownerless remembered agent pauses for approval and resumes without a participant', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            fakeAnthropicToolUse('toolu_1'),
            fakeAnthropicText('The number is 72019.'),
        ]),
    ]);

    $paused = (new RememberingApprovableAgent())->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals)->toHaveCount(1)
        ->and($paused->pendingApprovals->first()->id)->toBe('toolu_1')
        ->and($paused->conversationId)->not->toBeNull()
        ->and($paused->conversationUser)->toBeNull();

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $assistantRow = $messagesTable->find()
        ->where(['conversation_id' => $paused->conversationId, 'role' => 'assistant'])
        ->orderByDesc('id')
        ->first();

    expect($assistantRow->participant_type)->toBeNull()
        ->and($assistantRow->participant_id)->toBeNull()
        ->and($assistantRow->steps)->toHaveCount(1)
        ->and($assistantRow->steps[0]['tool_calls'])->toHaveCount(1)
        ->and($assistantRow->steps[0]['tool_calls'][0]['id'])->toBe('toolu_1')
        ->and($assistantRow->steps[0]['tool_calls'][0])->not->toHaveKey('result');

    $resumed = (new RememberingApprovableAgent())
        ->continue($paused->conversationId)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->hasPendingApprovals())->toBeFalse()
        ->and($resumed->text)->toBe('The number is 72019.')
        ->and($resumed->toolResults)->toHaveCount(1)
        ->and($resumed->toolResults->first()->result)->toBe('72019');
});

test('a resumed approval replays the paused turn replay blocks', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            aiHttpResponse([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'thinking',
                        'thinking' => 'Deciding whether to call the tool.',
                        'signature' => 'signature-1',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object)[],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            fakeAnthropicText('The number is 72019.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue();

    (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    $resumeMessages = collect(aiHttpRecorded())->last()[0]->data()['messages'];

    $assistantTurn = collection($resumeMessages)->firstMatch(['role' => 'assistant']);

    expect($assistantTurn['content'][0]['type'])->toBe('thinking')
        ->and($assistantTurn['content'][0]['signature'])->toBe('signature-1')
        ->and(collection($assistantTurn['content'])->firstMatch(['type' => 'tool_use'])['id'])->toBe('toolu_1');
});

test('a resume after a multi-step pause replays each step with its own signed thinking and answers its tool_use', function (): void {
    $message = fn(array $content, string $stopReason): AiHttpResponseDefinition => aiHttpResponse([
        'id' => 'msg',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => $content,
        'stop_reason' => $stopReason,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);

    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            $message([
                ['type' => 'thinking', 'thinking' => 'First the fixed number.', 'signature' => 'signature-1'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object)[]],
            ], 'tool_use'),
            $message([
                ['type' => 'thinking', 'thinking' => 'Now the gated number.', 'signature' => 'signature-2'],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'ApprovableNumberGenerator', 'input' => (object)[]],
            ], 'tool_use'),
            $message([['type' => 'text', 'text' => 'Both numbers are 72019.']], 'end_turn'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingMultiStepApprovableAgent())->forUser($user)->prompt('Generate both numbers', provider: 'anthropic');

    expect($paused->pendingApprovals->map(fn($approval): string => $approval->id)->toList())->toBe(['toolu_2']);

    $resumed = (new RememberingMultiStepApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

    $resumeMessages = collect(aiHttpRecorded())->last()[0]->data()['messages'];

    $turns = [];
    $signatures = [];

    foreach ($resumeMessages as $resumeMessage) {
        $blocks = $resumeMessage['content'] ?? [];

        $turns[] = [
            $resumeMessage['role'],
            array_map(fn(array $block): string => $block['tool_use_id'] ?? $block['id'] ?? $block['type'], $blocks),
        ];

        foreach ($blocks as $block) {
            if (!empty($block['signature'])) {
                $signatures[] = $block['signature'];
            }
        }
    }

    expect($resumed->text)->toBe('Both numbers are 72019.')
        ->and($turns)->toBe([
            ['user', ['text']],
            ['assistant', ['thinking', 'toolu_1']],
            ['user', ['toolu_1']],
            ['assistant', ['thinking', 'toolu_2']],
            ['user', ['toolu_2']],
        ])
        ->and($signatures)->toBe(['signature-1', 'signature-2']);
});

test('a streamed multi-step pause stores every step so the resume replays each one with its own blocks', function (): void {
    $sse = fn(array $events): AiHttpResponseDefinition => aiHttpResponse(
        implode("\n\n", array_map(fn(array $event): string => 'data: ' . json_encode($event), $events)) . "\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    );

    $step = fn(string $signature, string $toolId, string $toolName): AiHttpResponseDefinition => $sse([
        ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Deciding.']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => $signature]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => $toolId, 'name' => $toolName, 'input' => (object)[]]],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
        ['type' => 'content_block_stop', 'index' => 1],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
    ]);

    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            $step('signature-1', 'toolu_1', 'FixedNumberGenerator'),
            $step('signature-2', 'toolu_2', 'ApprovableNumberGenerator'),
            $sse([
                ['type' => 'message_start', 'message' => ['id' => 'msg_2', 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Both numbers are 72019.']],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]],
            ]),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingMultiStepApprovableAgent())->forUser($user)->stream('Generate both numbers', provider: 'anthropic');

    $paused->each(fn(): bool => true);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $steps = $messagesTable->find()
        ->where(['role' => 'assistant'])
        ->firstOrFail()
        ->steps;

    expect($steps)->toHaveCount(2)
        ->and($steps[0]['tool_calls'])->toHaveCount(1)
        ->and($steps[0]['tool_calls'][0]['id'])->toBe('toolu_1')
        ->and($steps[1]['tool_calls'])->toHaveCount(1)
        ->and($steps[1]['tool_calls'][0]['id'])->toBe('toolu_2')
        ->and($steps[0]['replay_blocks'][0]['signature'])->toBe('signature-1')
        ->and($steps[1]['replay_blocks'][0]['signature'])->toBe('signature-2');

    $resumed = (new RememberingMultiStepApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->stream(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

    $resumed->each(fn(): bool => true);

    $resumeMessages = collect(aiHttpRecorded())->last()[0]->data()['messages'];

    $turns = [];

    foreach ($resumeMessages as $resumeMessage) {
        $turns[] = [
            $resumeMessage['role'],
            array_map(fn(array $block): string => $block['tool_use_id'] ?? $block['id'] ?? $block['type'], $resumeMessage['content'] ?? []),
        ];
    }

    expect($turns)->toBe([
        ['user', ['text']],
        ['assistant', ['thinking', 'toolu_1']],
        ['user', ['toolu_1']],
        ['assistant', ['thinking', 'toolu_2']],
        ['user', ['toolu_2']],
    ]);
});

test('a resume on a different provider falls back to the generic mapping instead of replaying foreign blocks', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            aiHttpResponse([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'thinking',
                        'thinking' => 'Deciding whether to call the tool.',
                        'signature' => 'signature-1',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object)[],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            fakeAnthropicText('The number is 72019.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue();

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');

    foreach ($messagesTable->find()->where(['conversation_id' => $paused->conversationId, 'role' => 'assistant'])->all() as $row) {
        $meta = $row->meta;
        $meta['provider'] = 'openai';
        $messagesTable->updateAll(['meta' => $meta], ['id' => $row->id]);
    }

    (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    $resumeMessages = collect(aiHttpRecorded())->last()[0]->data()['messages'];

    $assistantTurn = collection($resumeMessages)->firstMatch(['role' => 'assistant']);

    expect(collection($assistantTurn['content'])->firstMatch(['type' => 'thinking']))->toBeNull()
        ->and(collection($assistantTurn['content'])->firstMatch(['type' => 'tool_use'])['id'])->toBe('toolu_1');
});

test('a resume that pauses again can itself be resumed', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            fakeAnthropicToolUse('toolu_1'),
            fakeAnthropicToolUse('toolu_2'),
            fakeAnthropicText('Both numbers generated.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate two numbers', provider: 'anthropic');

    $pausedAgain = (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($pausedAgain->hasPendingApprovals())->toBeTrue()
        ->and($pausedAgain->pendingApprovals->first()->id)->toBe('toolu_2');

    $resumed = (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

    expect($resumed->hasPendingApprovals())->toBeFalse()
        ->and($resumed->text)->toBe('Both numbers generated.');
});

test('a plain prompt after an abandoned pause settles the dangling tool call', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            fakeAnthropicToolUse('toolu_1'),
            fakeAnthropicText('Hi there.'),
            fakeAnthropicText('Hi again.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect($paused->hasPendingApprovals())->toBeTrue();

    $reply = (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt('Never mind, just say hi', provider: 'anthropic');

    expect($reply->text)->toBe('Hi there.');

    $messages = collect(aiHttpRecorded())->last()[0]->data()['messages'];
    $toolResults = [];

    foreach ($messages as $message) {
        $content = $message['content'] ?? [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_result') {
                $toolResults[] = $block;
            }
        }
    }

    expect($toolResults)->toHaveCount(1)
        ->and($toolResults[0]['tool_use_id'])->toBe('toolu_1');

    (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt('Say hi once more', provider: 'anthropic');

    $messages = array_values(collect(aiHttpRecorded())->last()[0]->data()['messages']);

    $toolUseIndex = null;

    foreach ($messages as $index => $message) {
        $content = $message['content'] ?? [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && $block['id'] === 'toolu_1') {
                $toolUseIndex = $index;

                break 2;
            }
        }
    }

    $answeringBlocks = [];

    foreach (($messages[$toolUseIndex + 1]['content'] ?? []) as $block) {
        if (is_array($block) && ($block['type'] ?? null) === 'tool_result') {
            $answeringBlocks[] = $block;
        }
    }

    expect($toolUseIndex)->not->toBeNull()
        ->and(collection($answeringBlocks)->extract('tool_use_id')->toList())->toBe(['toolu_1']);
});

test('a streamed resume with mismatched decisions throws before the stream begins and releases the claim', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            fakeAnthropicToolUse('toolu_1'),
            fakeAnthropicText('The number is 72019.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect(fn(): StreamableAgentResponse => (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->stream(Decisions::from(['bogus-id' => true]), provider: 'anthropic'))->toThrow(ApprovalMismatchException::class);

    $resumed = (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->text)->toBe('The number is 72019.');
});

test('a valid streamed resume checks a gated tool call for approval exactly once', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            fakeAnthropicToolUse('toolu_1'),
            fakeAnthropicText('The number is 72019.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    ApprovableNumberGenerator::$approvalChecks = 0;

    $response = (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->stream(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    $response->each(fn(): bool => true);

    expect(ApprovableNumberGenerator::$approvalChecks)->toBe(1);
});

test('a resume does not fail over to another provider and re-run the approved tool', function (): void {
    Configure::write('Ai.providers.primary', ['className' => AnthropicProvider::class, 'key' => 'test-key']);
    Configure::write('Ai.providers.backup', ['className' => AnthropicProvider::class, 'key' => 'test-key']);

    ApprovableNumberGenerator::$invocations = 0;

    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            fakeAnthropicToolUse('toolu_1'),
            aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
            aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: ['primary', 'backup']);

    expect(fn(): AgentResponse => (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: ['primary', 'backup']))->toThrow(RateLimitedException::class);

    expect(ApprovableNumberGenerator::$invocations)->toBe(1);
});

test('a successful resume records the approved result exactly once across history', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            fakeAnthropicToolUse('toolu_1'),
            fakeAnthropicText('The number is 72019.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    (new RememberingApprovableAgent())->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');

    $recorded = [];

    foreach ($messagesTable->find()->where(['conversation_id' => $paused->conversationId])->all() as $row) {
        foreach ((array)($row->steps ?? []) as $step) {
            foreach ((array)($step['tool_calls'] ?? []) as $toolCall) {
                if (($toolCall['id'] ?? null) === 'toolu_1' && array_key_exists('result', $toolCall)) {
                    $recorded[] = $toolCall['id'];
                }
            }
        }
    }

    expect($recorded)->toHaveCount(1);
});

test('a rejected resume stores and rehydrates the tool result as denied', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => fakeAnthropicToolUse('toolu_1'),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    $rejected = (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => false]), provider: 'anthropic');

    expect($rejected->hasPendingApprovals())->toBeFalse();

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $assistantRow = $messagesTable->find()
        ->where(['conversation_id' => $paused->conversationId, 'role' => 'assistant'])
        ->orderByDesc('id')
        ->first();

    expect($assistantRow->steps[0]['tool_calls'])->toHaveCount(1)
        ->and($assistantRow->steps[0]['tool_calls'][0])->toMatchArray(['denied' => true]);

    $store = new DatabaseConversationStore();
    $messages = $store->getLatestConversationMessages($paused->conversationId, 10);

    $toolResultMessage = null;

    foreach ($messages as $message) {
        if ($message instanceof ToolResultMessage) {
            $toolResultMessage = $message;

            break;
        }
    }

    expect($toolResultMessage->toolResults->first()->denied)->toBeTrue();
});

test('a wildcard rejection resume records the denial once, on the paused row', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => fakeAnthropicToolUse('toolu_1'),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['*' => false]), provider: 'anthropic');

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $assistantRows = $messagesTable->find()
        ->where(['conversation_id' => $paused->conversationId, 'role' => 'assistant'])
        ->all();

    expect($assistantRows)->toHaveCount(1)
        ->and($assistantRows->first()->steps[0]['tool_calls'])->toHaveCount(1)
        ->and($assistantRows->first()->steps[0]['tool_calls'][0])->toMatchArray(['id' => 'toolu_1', 'denied' => true]);

    $messages = (new DatabaseConversationStore())->getLatestConversationMessages($paused->conversationId, 10);

    expect($messages->filter(fn($message): bool => $message instanceof ToolResultMessage))->toHaveCount(1);
});

test('a resume that fails after the tool runs does not re-execute the tool on retry', function (): void {
    ApprovableNumberGenerator::$invocations = 0;

    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            fakeAnthropicToolUse('toolu_1'),
            aiHttpResponse(['error' => ['message' => 'Server error']], 500),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect(fn(): AgentResponse => (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic'))->toThrow(Exception::class);

    expect(ApprovableNumberGenerator::$invocations)->toBe(1);

    expect(fn(): AgentResponse => (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic'))->toThrow(ApprovalMismatchException::class);

    expect(ApprovableNumberGenerator::$invocations)->toBe(1);
});

test('a resume folds its result and reply into the paused row instead of writing a newer one', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            aiHttpResponse([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object)[],
                ], [
                    'type' => 'tool_use',
                    'id' => 'toolu_2',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object)[],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            fakeAnthropicText('The number is 72019.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];
    $store = new DatabaseConversationStore();

    $paused = (new RememberingApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect(array_map(fn($approval): string => $approval->id, $store->pendingApprovalsFor($paused->conversationId)))
        ->toBe(['toolu_1', 'toolu_2']);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');

    $pausedRowId = $messagesTable->find()
        ->select(['id'])
        ->where(['conversation_id' => $paused->conversationId, 'role' => 'assistant'])
        ->orderByDesc('id')
        ->firstOrFail()->id;

    (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true, 'toolu_2' => true]), provider: 'anthropic');

    $pausedRow = $messagesTable->get($pausedRowId);

    $newerRows = $messagesTable->find()
        ->where(['conversation_id' => $paused->conversationId, 'id >' => $pausedRowId])
        ->count();

    expect($newerRows)->toBe(0)
        ->and($pausedRow->content)->toBe('The number is 72019.')
        ->and($pausedRow->steps)->toHaveCount(2)
        ->and($store->pendingApprovalsFor($paused->conversationId))->toBe([]);
});

test('a completed two-step turn replays step by step on the next prompt', function (): void {
    $toolUse = fn(string $id, string $text): AiHttpResponseDefinition => aiHttpResponse([
        'id' => 'msg_' . $id,
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [
            ['type' => 'text', 'text' => $text],
            ['type' => 'tool_use', 'id' => $id, 'name' => 'FixedNumberGenerator', 'input' => (object)[]],
        ],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);

    $text = fn(string $text): AiHttpResponseDefinition => aiHttpResponse([
        'id' => 'msg_text',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);

    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            $toolUse('toolu_1', 'First number'),
            $toolUse('toolu_2', 'Second number'),
            $text('The numbers are 72019 and 72019.'),
            $text('Yes, both.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $first = (new RememberingToolUsingAgent())->forUser($user)->prompt('Generate two numbers', provider: 'anthropic');

    (new RememberingToolUsingAgent())->continue($first->conversationId, $user)->prompt('Are you sure?', provider: 'anthropic');

    $history = collect(aiHttpRecorded())->last()[0]->data()['messages'];

    expect($history)->toHaveCount(7)
        ->and($history[0])->toMatchArray(['role' => 'user'])
        ->and($history[1])->toMatchArray(['role' => 'assistant'])
        ->and($history[1]['content'])->toHaveCount(2)
        ->and($history[1]['content'][0])->toMatchArray(['type' => 'text', 'text' => 'First number'])
        ->and($history[1]['content'][1])->toMatchArray(['type' => 'tool_use', 'id' => 'toolu_1'])
        ->and($history[2])->toMatchArray(['role' => 'user'])
        ->and($history[2]['content'])->toHaveCount(1)
        ->and($history[2]['content'][0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_1'])
        ->and($history[3])->toMatchArray(['role' => 'assistant'])
        ->and($history[3]['content'])->toHaveCount(2)
        ->and($history[3]['content'][0])->toMatchArray(['type' => 'text', 'text' => 'Second number'])
        ->and($history[3]['content'][1])->toMatchArray(['type' => 'tool_use', 'id' => 'toolu_2'])
        ->and($history[4])->toMatchArray(['role' => 'user'])
        ->and($history[4]['content'])->toHaveCount(1)
        ->and($history[4]['content'][0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_2'])
        ->and($history[5])->toMatchArray(['role' => 'assistant'])
        ->and($history[5]['content'][0])->toMatchArray(['type' => 'text', 'text' => 'The numbers are 72019 and 72019.'])
        ->and($history[6])->toMatchArray(['role' => 'user'])
        ->and($history[6]['content'][0])->toMatchArray(['type' => 'text', 'text' => 'Are you sure?']);
});

test('a turn that pauses twice replays every step of the turn with its replay blocks on the second resume', function (): void {
    $gated = fn(string $signature, string $id): AiHttpResponseDefinition => aiHttpResponse([
        'id' => 'msg_' . $id,
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [
            ['type' => 'thinking', 'thinking' => 'Deciding.', 'signature' => $signature],
            ['type' => 'tool_use', 'id' => $id, 'name' => 'ApprovableNumberGenerator', 'input' => (object)[]],
        ],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);

    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            $gated('signature-1', 'toolu_1'),
            $gated('signature-2', 'toolu_2'),
            aiHttpResponse([
                'id' => 'msg_text',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Both numbers are 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $first = (new RememberingMultiStepApprovableAgent())->forUser($user)->prompt('Generate two gated numbers', provider: 'anthropic');

    $second = (new RememberingMultiStepApprovableAgent())
        ->continue($first->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($first->pendingApprovals->map(fn($approval): string => $approval->id)->toList())->toBe(['toolu_1'])
        ->and($second->pendingApprovals->map(fn($approval): string => $approval->id)->toList())->toBe(['toolu_2']);

    (new RememberingMultiStepApprovableAgent())
        ->continue($first->conversationId, $user)
        ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

    $history = collect(aiHttpRecorded())->last()[0]->data()['messages'];

    expect($history)->toHaveCount(5)
        ->and($history[0])->toMatchArray(['role' => 'user'])
        ->and($history[1])->toMatchArray(['role' => 'assistant'])
        ->and($history[1]['content'][0])->toMatchArray(['type' => 'thinking', 'signature' => 'signature-1'])
        ->and($history[2])->toMatchArray(['role' => 'user'])
        ->and($history[2]['content'][0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_1'])
        ->and($history[3])->toMatchArray(['role' => 'assistant'])
        ->and($history[3]['content'][0])->toMatchArray(['type' => 'thinking', 'signature' => 'signature-2'])
        ->and($history[4])->toMatchArray(['role' => 'user'])
        ->and($history[4]['content'][0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_2']);
});

test('a plain call sharing a step with a gated call runs at the pause and both results land on the paused row', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            aiHttpResponse([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_plain', 'name' => 'FixedNumberGenerator', 'input' => (object)[]],
                    ['type' => 'tool_use', 'id' => 'toolu_gated', 'name' => 'ApprovableNumberGenerator', 'input' => (object)[]],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            fakeAnthropicText('Both done.'),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingMultiStepApprovableAgent())->forUser($user)->prompt('Go', provider: 'anthropic');

    $store = new DatabaseConversationStore();

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $pausedSteps = $messagesTable->find()
        ->where(['conversation_id' => $paused->conversationId, 'role' => 'assistant'])
        ->firstOrFail()->steps;
    $pausedCalls = collect($pausedSteps[0]['tool_calls'])->indexBy('id')->toArray();
    $pending = $store->pendingApprovalsFor($paused->conversationId);

    expect($pausedCalls['toolu_plain'])->toHaveKey('result')
        ->and($pausedCalls['toolu_plain'])->not->toHaveKey('approval_reason')
        ->and($pausedCalls['toolu_gated'])->toHaveKey('approval_reason')
        ->and($pausedCalls['toolu_gated'])->not->toHaveKey('result')
        ->and($pending)->toHaveCount(1)
        ->and($pending[0]->id)->toBe('toolu_gated');

    (new RememberingMultiStepApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_gated' => true]), provider: 'anthropic');

    $sentResultIds = collect(collect(aiHttpRecorded())->last()[0]->data()['messages'])
        ->unfold(fn(array $message): array => is_array($message['content'] ?? null) ? array_values($message['content']) : [])
        ->filter(fn(mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_result')
        ->map(fn(array $result): string => (string)$result['tool_use_id'])
        ->toList();

    $row = $messagesTable->find()->where(['role' => 'assistant'])->firstOrFail();
    $calls = collect($row->steps[0]['tool_calls'])->indexBy('id')->toArray();

    expect($sentResultIds)->toBe(['toolu_plain', 'toolu_gated'])
        ->and($calls['toolu_gated'])->toHaveKey('result')
        ->and($store->pendingApprovalsFor($paused->conversationId))->toBe([]);
});

test('a resume that dies after a step fails the row it paused on instead of writing a newer one', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            aiHttpResponse([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ApprovableNumberGenerator', 'input' => (object)[]]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            aiHttpResponse([
                'id' => 'msg_tool_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'FixedNumberGenerator', 'input' => (object)[]]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            aiHttpResponse(['error' => ['message' => 'Server error']], 500),
            aiHttpResponse(['error' => ['message' => 'Server error']], 500),
            aiHttpResponse(['error' => ['message' => 'Server error']], 500),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingMultiStepApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $pausedRowId = $messagesTable->find()
        ->where(['conversation_id' => $paused->conversationId, 'role' => 'assistant'])
        ->orderByDesc('id')
        ->firstOrFail()->id;

    expect(fn(): mixed => (new RememberingMultiStepApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic'))->toThrow(ClientException::class);

    $rows = $messagesTable->find()
        ->where(['conversation_id' => $paused->conversationId, 'role' => 'assistant'])
        ->all()
        ->toList();

    $steps = $rows[0]->steps;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($pausedRowId)
        ->and($rows[0]->status)->toBe(MessageStatus::Failed)
        ->and($steps)->toHaveCount(2)
        ->and($steps[0]['tool_calls'][0])->toMatchArray(['id' => 'toolu_1', 'result' => '72019'])
        ->and($steps[1]['tool_calls'][0])->toMatchArray(['id' => 'toolu_2', 'result' => '72019']);
});

test('a resume that dies before its first step still fails the row its approval was written to', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            aiHttpResponse([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ApprovableNumberGenerator', 'input' => (object)[]]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            aiHttpResponse(['error' => ['message' => 'Server error']], 500),
            aiHttpResponse(['error' => ['message' => 'Server error']], 500),
            aiHttpResponse(['error' => ['message' => 'Server error']], 500),
            aiHttpResponse(['error' => ['message' => 'Server error']], 500),
        ]),
    ]);

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];

    $paused = (new RememberingMultiStepApprovableAgent())->forUser($user)->prompt('Generate a number', provider: 'anthropic');

    expect(fn(): mixed => (new RememberingMultiStepApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic'))->toThrow(ClientException::class);

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $rows = $messagesTable->find()
        ->where(['conversation_id' => $paused->conversationId, 'role' => 'assistant'])
        ->all()
        ->toList();

    $steps = $rows[0]->steps;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe(MessageStatus::Failed)
        ->and($rows[0]->meta['error'])->not->toBeEmpty()
        ->and($steps)->toHaveCount(1)
        ->and($steps[0]['tool_calls'][0])->toMatchArray(['id' => 'toolu_1', 'result' => '72019']);
});
