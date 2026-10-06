<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Crustum\Ai\Enums\MessageStatus;
use Crustum\Ai\Model\Entity\Conversation;
use Crustum\Ai\Model\Entity\ConversationMessage;
use Crustum\Ai\Test\Support\Database\ConversationFixtures;
use Crustum\Ai\Test\Support\Database\ConversationSchema;
use TestApp\Model\Entity\User;

beforeEach(function (): void {
    Configure::write('Ai.conversations.connection', 'test');
});

test('model can retrieve conversations using relationship', function (): void {
    /** @var \TestApp\Model\Table\UsersTable $usersTable */
    $usersTable = $this->getTableLocator()->get('Users');
    $user = $usersTable->saveOrFail($usersTable->newEntity(['name' => 'Larry']));
    $otherUser = $usersTable->saveOrFail($usersTable->newEntity(['name' => 'Abigail']));

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $conversationsTable */
    $conversationsTable = $this->getTableLocator()->get('Crustum/Ai.Conversations');

    $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => ConversationFixtures::CONVERSATION_ONE,
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'title' => 'First Conversation',
        'created' => now()->subMinutes(10),
        'modified' => now()->subMinutes(10),
    ]));
    $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => ConversationFixtures::CONVERSATION_TWO,
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'title' => 'Second Conversation',
        'created' => now()->subMinutes(5),
        'modified' => now()->subMinutes(5),
    ]));
    $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => ConversationFixtures::CONVERSATION_THREE,
        'participant_type' => User::class,
        'participant_id' => (string)$otherUser->id,
        'title' => 'Other Conversation',
        'created' => now(),
        'modified' => now(),
    ]));

    $conversations = collect(
        $usersTable->Conversations->find()
            ->where([
                'participant_type' => User::class,
                'participant_id' => (string)$user->id,
            ])
            ->orderByDesc('modified')
            ->all(),
    );

    expect($conversations)->toHaveCount(2)
        ->and($conversations->extract('id')->toList())->toEqual([ConversationFixtures::CONVERSATION_TWO, ConversationFixtures::CONVERSATION_ONE])
        ->and($conversations->first())->toBeInstanceOf(Conversation::class);
});

test('conversation can retrieve messages using relationship', function (): void {
    /** @var \TestApp\Model\Table\UsersTable $usersTable */
    $usersTable = $this->getTableLocator()->get('Users');
    $user = $usersTable->saveOrFail($usersTable->newEntity(['name' => 'Larry']));

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $conversationsTable */
    $conversationsTable = $this->getTableLocator()->get('Crustum/Ai.Conversations');
    $conversation = $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => ConversationFixtures::CONVERSATION_ONE,
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'title' => 'Conversation',
        'created' => now(),
        'modified' => now(),
    ]));

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $messagesTable->saveOrFail($messagesTable->newEntity([
        'id' => ConversationFixtures::MESSAGE_ONE,
        'conversation_id' => $conversation->id,
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'agent' => 'Agent',
        'role' => 'user',
        'content' => 'Hello',
        'attachments' => [],
        'steps' => [],
        'usage' => [],
        'meta' => [],
        'status' => MessageStatus::Completed,
        'created' => now(),
        'modified' => now(),
    ]));

    $conversation = $conversationsTable->get($conversation->id, contain: ['ConversationMessages']);
    $messages = collect($conversation->messages);

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBeInstanceOf(ConversationMessage::class)
        ->and($messages->first()->content)->toBe('Hello')
        ->and($messages->first()->attachments)->toBeArray();
});

test('message tool calls and results flatten across steps in order and serialize with the model', function (): void {
    /** @var \TestApp\Model\Table\UsersTable $usersTable */
    $usersTable = $this->getTableLocator()->get('Users');
    $user = $usersTable->saveOrFail($usersTable->newEntity(['name' => 'Larry']));

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $conversationsTable */
    $conversationsTable = $this->getTableLocator()->get('Crustum/Ai.Conversations');
    $conversation = $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => ConversationFixtures::CONVERSATION_ONE,
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'title' => 'Conversation',
        'created' => now(),
        'modified' => now(),
    ]));

    /** @var \Crustum\Ai\Model\Table\ConversationMessagesTable $messagesTable */
    $messagesTable = $this->getTableLocator()->get('Crustum/Ai.ConversationMessages');
    $messagesTable->saveOrFail($messagesTable->newEntity([
        'id' => ConversationFixtures::MESSAGE_ONE,
        'conversation_id' => $conversation->id,
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'agent' => 'Agent',
        'role' => 'assistant',
        'content' => 'Done',
        'attachments' => [],
        'steps' => [
            ['tool_calls' => [['id' => 'call-1', 'name' => 'a', 'arguments' => [], 'result' => 'x', 'reasoning_encrypted_content' => 'gAAAAA']], 'replay_blocks' => []],
            ['tool_calls' => [['id' => 'call-2', 'name' => 'b', 'arguments' => [], 'result' => 'y']], 'replay_blocks' => []],
        ],
        'usage' => [],
        'meta' => [],
        'status' => MessageStatus::Completed,
        'created' => now(),
        'modified' => now(),
    ]));

    $message = $conversationsTable->get($conversation->id, contain: ['ConversationMessages'])->messages[0];

    expect(array_column($message->tool_calls, 'id'))->toBe(['call-1', 'call-2'])
        ->and($message->tool_results)->toBe([
            ['id' => 'call-1', 'name' => 'a', 'arguments' => [], 'result' => 'x'],
            ['id' => 'call-2', 'name' => 'b', 'arguments' => [], 'result' => 'y'],
        ])
        ->and($message->toArray())->toHaveKeys(['tool_calls', 'tool_results']);
});

test('conversation can retrieve its participant using relationship', function (): void {
    /** @var \TestApp\Model\Table\UsersTable $usersTable */
    $usersTable = $this->getTableLocator()->get('Users');
    $user = $usersTable->saveOrFail($usersTable->newEntity(['name' => 'Larry']));

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $conversationsTable */
    $conversationsTable = $this->getTableLocator()->get('Crustum/Ai.Conversations');
    $conversation = $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => ConversationFixtures::CONVERSATION_ONE,
        'participant_type' => User::class,
        'participant_id' => (string)$user->id,
        'title' => 'Conversation',
        'created' => now(),
        'modified' => now(),
    ]));

    expect($conversation->participant)->toBeInstanceOf(User::class)
        ->and($conversation->participant->id)->toBe($user->id);
});

test('conversation model uses configured database connection', function (): void {
    ConversationSchema::create('secondary');

    Configure::write('Ai.conversations.connection', 'secondary');
    TableRegistry::getTableLocator()->clear();

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $conversationsTable */
    $conversationsTable = TableRegistry::getTableLocator()->get('Crustum/Ai.Conversations');
    $conversationsTable->saveOrFail($conversationsTable->newEntity([
        'id' => ConversationFixtures::SECONDARY_CONVERSATION,
        'participant_type' => null,
        'participant_id' => ConversationFixtures::SECONDARY_PARTICIPANT,
        'title' => 'On Secondary DB',
        'created' => now(),
        'modified' => now(),
    ]));

    $conversation = $conversationsTable->get(ConversationFixtures::SECONDARY_CONVERSATION);

    /** @var \Crustum\Ai\Model\Table\ConversationsTable $testConversationsTable */
    $testConversationsTable = TableRegistry::getTableLocator()->get('TestAgentConversations', [
        'className' => 'Crustum/Ai.Conversations',
        'connectionName' => 'test',
    ]);

    expect($conversation)->not->toBeNull()
        ->and($conversation->title)->toBe('On Secondary DB')
        ->and($testConversationsTable->exists(['id' => ConversationFixtures::SECONDARY_CONVERSATION]))->toBeFalse();
})->after(function (): void {
    Configure::write('Ai.conversations.connection', 'test');
    TableRegistry::getTableLocator()->clear();
    ConnectionManager::get('secondary')->execute('DELETE FROM agent_conversation_messages');
    ConnectionManager::get('secondary')->execute('DELETE FROM agent_conversations');
});
