<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Approvals\ApprovalMismatchException;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Exception\RateLimitedException;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Providers\AnthropicProvider;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Test\Fixtures\Agents\RememberingApprovableAgent;
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
        ->order(['id' => 'DESC'])
        ->first();

    expect($assistantRow->tool_calls)->toHaveCount(1)
        ->and($assistantRow->tool_calls[0]['id'])->toBe('toolu_1')
        ->and($assistantRow->tool_results)->toBeEmpty();

    $resumed = (new RememberingApprovableAgent())
        ->continue($paused->conversationId, $user)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->hasPendingApprovals())->toBeFalse()
        ->and($resumed->text)->toBe('The number is 72019.')
        ->and($resumed->toolResults)->toHaveCount(1)
        ->and($resumed->toolResults->first()->result)->toBe('72019');
});

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
        ->order(['id' => 'DESC'])
        ->first();

    expect($assistantRow->participant_type)->toBeNull()
        ->and($assistantRow->participant_id)->toBeNull()
        ->and($assistantRow->tool_calls[0]['id'])->toBe('toolu_1')
        ->and($assistantRow->tool_results)->toBeEmpty();

    $resumed = (new RememberingApprovableAgent())
        ->continue($paused->conversationId)
        ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

    expect($resumed->hasPendingApprovals())->toBeFalse()
        ->and($resumed->text)->toBe('The number is 72019.')
        ->and($resumed->toolResults)->toHaveCount(1)
        ->and($resumed->toolResults->first()->result)->toBe('72019');
});

test('a resumed approval replays the paused turn provider content blocks', function (): void {
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
        foreach ($row->tool_results ?? [] as $result) {
            if (($result['id'] ?? null) === 'toolu_1') {
                $recorded[] = $result['id'];
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
        ->order(['id' => 'DESC'])
        ->first();

    expect($assistantRow->tool_results[0]['denied'])->toBeTrue();

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
